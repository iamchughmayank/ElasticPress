<?php
/**
 * Class responsible for rendering the filters.
 *
 * @since 4.2.0
 * @package elasticpress
 */

namespace ElasticPress\Feature\Facets;

use ElasticPress\Features as Features;
use ElasticPress\Utils as Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Facets render class
 */
class Renderer {
	/**
	 * Output the widget or block HTML.
	 *
	 * @param array $args     Widget args
	 * @param array $instance Instance settings
	 */
	public function render( $args, $instance ) {
		global $wp_query;

		$feature = Features::factory()->get_registered_feature( 'facets' );

		if ( $wp_query->get( 'ep_facet', false ) && ! $feature->is_facetable( $wp_query ) ) {
			return false;
		}

		$es_success = ( ! empty( $wp_query->elasticsearch_success ) ) ? true : false;

		if ( ! $es_success ) {
			return;
		}

		if ( empty( $instance['facet'] ) ) {
			return;
		}

		$taxonomy = $instance['facet'];

		if ( ! is_search() ) {
			$post_type = $wp_query->get( 'post_type' );

			if ( empty( $post_type ) ) {
				$post_type = 'post';
			}

			if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				return;
			}
		}

		$selected_filters = $feature->get_selected();

		$match_type = ( ! empty( $instance['match_type'] ) ) ? $instance['match_type'] : 'all';

		/**
		 * Get all the terms so we know if we should output the widget
		 */
		$terms = get_terms(
			/**
			 * Filter arguments passed to get_terms() while getting all possible terms for the facet widget.
			 *
			 * @since  3.5.0
			 * @hook ep_facet_search_get_terms_args
			 * @param  {array} $query Weighting query
			 * @param  {string} $post_type Post type
			 * @param  {array} $args WP Query arguments
			 * @return  {array} New query
			 */
			apply_filters(
				'ep_facet_search_get_terms_args',
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
				],
				$args,
				$instance
			)
		);

		/**
		 * Terms validity check
		 */
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$terms_by_slug = array();

		foreach ( $terms as $term ) {
			$terms_by_slug[ $term->slug ] = $term;

			if ( ! empty( $GLOBALS['ep_facet_aggs'][ $taxonomy ][ $term->slug ] ) ) {
				$term->count = $GLOBALS['ep_facet_aggs'][ $taxonomy ][ $term->slug ];
			} else {
				$term->count = 0;
			}
		}

		/**
		 * Check to make sure all terms exist before proceeding
		 */
		if ( ! empty( $selected_filters['taxonomies'][ $taxonomy ] ) && ! empty( $selected_filters['taxonomies'][ $taxonomy ]['terms'] ) ) {
			foreach ( $selected_filters['taxonomies'][ $taxonomy ]['terms'] as $term_slug => $nothing ) {
				if ( empty( $terms_by_slug[ $term_slug ] ) ) {
					/**
					 * Term does not exist!
					 */
					return;
				}
			}
		}

		$orderby = isset( $instance['orderby'] ) ? $instance['orderby'] : 'count';
		$order   = isset( $instance['order'] ) ? $instance['order'] : 'count';

		$terms = Utils\get_term_tree( $terms, $orderby, $order, true );

		$outputted_terms = array();

		echo wp_kses_post( $args['before_widget'] );

		if ( ! empty( $instance['title'] ) ) {
			echo wp_kses_post( $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'] );
		}

		$taxonomy_object = get_taxonomy( $taxonomy );

		/**
		 * Filter facet search threshold
		 *
		 * @hook ep_facet_search_threshold
		 * @param  {int} $search_threshold Search threshold
		 * @param  {string} $taxonomy Current taxonomy
		 * @return  {int} New threshold
		 */
		$search_threshold = apply_filters( 'ep_facet_search_threshold', 15, $taxonomy );
		?>

		<div class="terms <?php if ( count( $terms_by_slug ) > $search_threshold ) : ?>searchable<?php endif; ?>">
			<?php if ( count( $terms_by_slug ) > $search_threshold ) : ?>
				<?php // translators: Taxonomy Name ?>
				<input class="facet-search" type="search" placeholder="<?php printf( esc_html__( 'Search %s', 'elasticpress' ), esc_attr( $taxonomy_object->labels->name ) ); ?>">
				<?php
			endif;
			ob_start();
			?>

			<div class="inner">
				<?php if ( ! empty( $selected_filters['taxonomies'][ $taxonomy ] ) ) : ?>
					<?php
					foreach ( $selected_filters['taxonomies'][ $taxonomy ]['terms'] as $term_slug => $value ) :
						if ( ! empty( $outputted_terms[ $term_slug ] ) ) {
							continue;
						}

						$term = $terms_by_slug[ $term_slug ];

						if ( empty( $term->parent ) && empty( $term->children ) ) {
							$outputted_terms[ $term_slug ] = $term;
							$new_filters                   = $selected_filters;

							if ( ! empty( $new_filters['taxonomies'][ $taxonomy ] ) && ! empty( $new_filters['taxonomies'][ $taxonomy ]['terms'][ $term_slug ] ) ) {
								unset( $new_filters['taxonomies'][ $taxonomy ]['terms'][ $term_slug ] );
							}

							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
							echo $this->get_facet_term_html(
								$term,
								$feature->build_query_url( $new_filters ),
								true
							);
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							/**
							 * This code is so that when we encounter a selected child/parent term, we push it's whole branch
							 * to the top. Very very painful.
							 */
							$top_of_tree = $term;
							$i           = 0;

							/**
							 * Get top of tree
							 */
							while ( true && $i < 10 ) {
								if ( ! empty( $term->parent_slug ) ) {
									$top_of_tree = $terms_by_slug[ $term->parent_slug ];
								} else {
									break;
								}

								$i++;
							}

							$flat_ordered_terms = array();

							$flat_ordered_terms[] = $top_of_tree;

							$to_process = $this->order_by_selected( $top_of_tree->children, $selected_filters['taxonomies'][ $taxonomy ]['terms'], $order, $orderby );

							while ( ! empty( $to_process ) ) {
								$term = array_shift( $to_process );

								$flat_ordered_terms[] = $term;

								if ( ! empty( $term->children ) ) {
									$to_process = array_merge( $this->order_by_selected( $term->children, $selected_filters['taxonomies'][ $taxonomy ]['terms'], $order, $orderby ), $to_process );
								}
							}

							foreach ( $flat_ordered_terms as $term ) {
								$selected                       = ! empty( $selected_filters['taxonomies'][ $taxonomy ]['terms'][ $term->slug ] );
								$outputted_terms[ $term->slug ] = $term;
								$new_filters                    = $selected_filters;

								if ( $selected ) {
									if ( ! empty( $new_filters['taxonomies'][ $taxonomy ] ) && ! empty( $new_filters['taxonomies'][ $taxonomy ]['terms'][ $term->slug ] ) ) {
										unset( $new_filters['taxonomies'][ $taxonomy ]['terms'][ $term->slug ] );
									}
								} else {
									if ( empty( $new_filters['taxonomies'][ $taxonomy ] ) ) {
										$new_filters['taxonomies'][ $taxonomy ] = array(
											'terms' => array(),
										);
									}

									$new_filters['taxonomies'][ $taxonomy ]['terms'][ $term->slug ] = true;
								}

								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
								echo $this->get_facet_term_html(
									$term,
									$feature->build_query_url( $new_filters ),
									$selected
								);
								// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							}
						}
					endforeach;
					?>
				<?php endif; ?>

				<?php
				foreach ( $terms as $term ) :
					if ( ! empty( $outputted_terms[ $term->slug ] ) ) {
						continue;
					}

					$new_filters = $selected_filters;

					if ( empty( $new_filters['taxonomies'][ $taxonomy ] ) ) {
						$new_filters['taxonomies'][ $taxonomy ] = array(
							'terms' => array(),
						);
					}

					$new_filters['taxonomies'][ $taxonomy ]['terms'][ $term->slug ] = true;

					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $this->get_facet_term_html(
						$term,
						$feature->build_query_url( $new_filters )
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
				endforeach;
				?>
			</div>
			<?php $facet_html = ob_get_clean(); ?>

			<?php
			// phpcs:disable
			/**
			 * Filter facet search widget HTML
			 *
			 * @hook ep_facet_search_widget
			 * @param  {string} $facet_html Widget HTML
			 * @param  {array} $selected_filters Selected filters
			 * @param  {array} $terms_by_slug Terms by slug
			 * @param  {array} $outputted_terms Outputted $terms
			 * @param  {string} $title Widget title
			 * @return  {string} New HTML
			 */
			echo apply_filters( 'ep_facet_search_widget', $facet_html, $selected_filters, $terms_by_slug, $outputted_terms, $instance['title'] );
			// phpcs:enable
			?>
		</div>
		<?php

		// Enqueue Script & Styles
		wp_enqueue_script( 'elasticpress-facets' );
		wp_enqueue_style( 'elasticpress-facets' );

		echo wp_kses_post( $args['after_widget'] );
	}

	/**
	 * Get the markup for an individual facet item.
	 *
	 * @param WP_Term $term     Term object.
	 * @param string  $url      Filter URL.
	 * @param boolean $selected Whether the term is currently selected.
	 * @return string HTML for an individual facet term.
	 */
	public function get_facet_term_html( $term, $url, $selected = false ) {
		$href = sprintf(
			'href="%s"',
			esc_url( $url )
		);

		/**
		 * Filter the label for an individual facet term.
		 *
		 * @since 3.6.3
		 * @hook ep_facet_widget_term_label
		 * @param {string} $label Facet term label.
		 * @param {WP_Term} $term Term object.
		 * @param {boolean} $selected Whether the term is selected.
		 * @return {string} Individual facet term label.
		 */
		$label = apply_filters( 'ep_facet_widget_term_label', $term->name, $term, $selected );

		/**
		 * Filter the accessible label for an individual facet term link.
		 *
		 * Used as the aria-label attribute for filter links. The accessible
		 * label should include additional context around what action will be
		 * performed by visiting the link, such as whether the filter will be
		 * added or removed.
		 *
		 * @since 4.0.0
		 * @hook ep_facet_widget_term_accessible_label
		 * @param {string} $label Facet term accessible label.
		 * @param {WP_Term} $term Term object.
		 * @param {boolean} $selected Whether the term is selected.
		 * @return {string} Individual facet term accessible label.
		 */
		$accessible_label = apply_filters(
			'ep_facet_widget_term_accessible_label',
			$selected
				/* translators: %s: Filter term name. */
				? sprintf( __( 'Remove filter: %s', 'elasticpress' ), $term->name )
				/* translators: %s: Filter term name. */
				: sprintf( __( 'Apply filter: %s', 'elasticpress' ), $term->name ),
			$term,
			$selected
		);

		$link = sprintf(
			'<a aria-label="%1$s" %2$s rel="nofollow"><div class="ep-checkbox %3$s" role="presentation"></div>%4$s</a>',
			esc_attr( $accessible_label ),
			$term->count ? $href : 'aria-role="link" aria-disabled="true"',
			$selected ? 'checked' : '',
			wp_kses_post( $label )
		);

		$html = sprintf(
			'<div class="term level-%1$d %2$s %3$s" data-term-name="%4$s" data-term-slug="%5$s">%6$s</div>',
			absint( $term->level ),
			$selected ? 'selected' : '',
			! $term->count ? 'empty-term' : '',
			esc_attr( strtolower( $term->name ) ),
			esc_attr( strtolower( $term->slug ) ),
			$link
		);

		/**
		 * Filter the HTML for an individual facet term.
		 *
		 * For term search to work correctly the outermost wrapper of the term
		 * HTML must have data-term-name and data-term-slug attributes set to
		 * lowercase versions of the term name and slug respectively.
		 *
		 * @since 3.6.3
		 * @hook ep_facet_widget_term_html
		 * @param {string} $html Facet term HTML.
		 * @param {WP_Term} $term Term object.
		 * @param {string} $url Filter URL.
		 * @param {boolean} $selected Whether the term is selected.
		 * @return {string} Individual facet term HTML.
		 */
		return apply_filters( 'ep_facet_widget_term_html', $html, $term, $url, $selected );
	}

	/**
	 * Order terms putting selected at the top
	 *
	 * @param  array  $terms Array of terms
	 * @param  array  $selected_terms Selected terms
	 * @param  string $order The order to sort from. Desc or Asc.
	 * @param  string $orderby The orderby to sort items from.
	 * @return array
	 */
	private function order_by_selected( $terms, $selected_terms, $order = false, $orderby = false ) {
		$ordered_terms = [];
		$terms_by_slug = [];

		foreach ( $terms as $term ) {
			$terms_by_slug[ $term->slug ] = $term;
		}

		foreach ( $selected_terms as $term_slug ) {
			if ( ! empty( $terms_by_slug[ $term_slug ] ) ) {
				$ordered_terms[ $term_slug ] = $terms_by_slug[ $term_slug ];
			}
		}

		foreach ( $terms_by_slug as $term_slug => $term ) {
			if ( empty( $ordered_terms[ $term_slug ] ) ) {
				$ordered_terms[ $term_slug ] = $terms_by_slug[ $term_slug ];
			}
		}

		if ( 'count' === $orderby ) {
			if ( 'asc' === $order ) {
				uasort(
					$ordered_terms,
					function( $a, $b ) {
						return $a->count > $b->count;
					}
				);
			} else {
				uasort(
					$ordered_terms,
					function( $a, $b ) {
						return $a->count < $b->count;
					}
				);
			}
		} else {
			if ( 'asc' === $order ) {
				ksort( $ordered_terms );
			} else {
				krsort( $ordered_terms );
			}
		}

		return array_values( $ordered_terms );
	}
}
