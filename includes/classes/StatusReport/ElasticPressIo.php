<?php
/**
 * ElasticPress.io report class
 *
 * @since 4.4.0
 * @package elasticpress
 */

namespace ElasticPress\StatusReport;

use ElasticPress\Indexables;
use ElasticPress\Feature\InstantResults;
use ElasticPress\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * ElasticPressIo report class
 *
 * @package ElasticPress
 */
class ElasticPressIo extends Report {

	/**
	 * Return the report title
	 *
	 * @return string
	 */
	public function get_title() : string {
		return __( 'ElasticPress.io', 'elasticpress' );
	}

	/**
	 * Return the report fields
	 *
	 * @return array
	 */
	public function get_groups() : array {
		return [
			$this->get_autosuggest_group(),
			$this->get_instant_results_group(),
		];
	}

	/**
	 * Process the ElasticPress.io Autosuggest allowed parameters.
	 *
	 * @return array
	 */
	protected function get_autosuggest_group() : array {
		$title = __( 'Allowed Autosuggest Parameters', 'elasticpress' );

		$autosuggest_feature = \ElasticPress\Features::factory()->get_registered_feature( 'autosuggest' );
		$allowed_params      = $autosuggest_feature->epio_autosuggest_set_and_get();

		if ( empty( $allowed_params ) ) {
			$fields['not_available'] = [
				'label' => __( 'Allowed Autosuggest Parameters', 'elasticpress' ),
				'value' => __( 'Allowed autosuggest parameters info not available.', 'elasticpress' ),
			];

			return [
				[
					'title'  => $title,
					'fields' => $fields,
				],
			];
		}

		$allowed_params = wp_parse_args(
			$allowed_params,
			[
				'postTypes'    => [],
				'postStatus'   => [],
				'searchFields' => [],
				'returnFields' => '',
			]
		);

		$fields = [
			'Post Types'      => wp_sprintf( esc_html__( '%l', 'elasticpress' ), $allowed_params['postTypes'] ),
			'Post Status'     => wp_sprintf( esc_html__( '%l', 'elasticpress' ), $allowed_params['postStatus'] ),
			'Search Fields'   => wp_sprintf( esc_html__( '%l', 'elasticpress' ), $allowed_params['searchFields'] ),
			'Returned Fields' => wp_sprintf( esc_html( var_export( $allowed_params['returnFields'], true ) ) ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		];

		$formatted_fields = [];

		foreach ( $fields as $label => $value ) {
			$formatted_fields[ sanitize_title( $label ) ] = [
				'label' => $label,
				'value' => $value,
			];
		}

		return [
			'title'  => $title,
			'fields' => $formatted_fields,
		];
	}

	/**
	 * Process the ElasticPress.io Instant Results templates.
	 *
	 * @return array
	 */
	protected function get_instant_results_group() : array {
		$title  = __( 'Instant Results Template', 'elasticpress' );
		$fields = [];

		if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
			$sites = Utils\get_sites();

			foreach ( $sites as $site ) {
				if ( ! Utils\is_site_indexable( $site['blog_id'] ) ) {
					continue;
				}

				switch_to_blog( $site['blog_id'] );

				$field  = $this->get_instant_results_field();
				$fields = array_merge( $fields, $field );

				restore_current_blog();
			}
		} else {
			$fields = $this->get_instant_results_field();
		}

		return [
			'title'  => $title,
			'fields' => $fields,
		];
	}

	/**
	 * Process the ElasticPress.io Instant Results template.
	 *
	 * @return array|null
	 */
	protected function get_instant_results_field() : array {
		$index = Indexables::factory()->get( 'post' )->get_index_name();

		if ( ! $index ) {
			return [];
		}

		$feature  = new InstantResults\InstantResults();
		$template = $feature->epio_get_search_template();

		if ( is_wp_error( $template ) ) {
			return [
				$index => [
					'label' => $index,
					'value' => $template->get_error_message(),
				],
			];
		}

		return [
			$index => [
				'label' => $index,
				'value' => $template,
			],
		];
	}
}
