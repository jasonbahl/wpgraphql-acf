<?php
/**
 * ACF extension for WP-GraphQL
 *
 * @package wp-graphql-acf
 */

namespace WPGraphQL\Acf\Admin;

use WP_Post;
use WPGraphQL\Acf\AcfGraphQLFieldType;
use WPGraphQL\Acf\LocationRules\LocationRules;
use WPGraphQL\Acf\Utils;
use WPGraphQL\Acf\Registry;


/**
 * Class ACF_Settings
 *
 * @package WPGraphQL\ACF
 */
class Settings {

	/**
	 * @var bool
	 */
	protected $is_acf6_or_higher = false;

	/**
	 * @var \WPGraphQL\Acf\Registry
	 */
	protected $registry;

	/**
	 * @return \WPGraphQL\Acf\Registry
	 */
	protected function get_registry(): Registry {
		if ( ! $this->registry instanceof Registry ) {
			$this->registry = new Registry();
		}

		return $this->registry;
	}

	/**
	 * Initialize ACF Settings for the plugin
	 */
	public function init(): void {

		$this->is_acf6_or_higher = defined( 'ACF_MAJOR_VERSION' ) && version_compare( ACF_MAJOR_VERSION, '6', '>=' );

		/**
		 * Add settings to individual fields to allow each field granular control
		 * over how it's shown in the GraphQL Schema
		 */
		add_filter( 'acf/field_group/additional_field_settings_tabs', static function ( $tabs ) {
			$tabs['graphql'] = __( 'GraphQL', 'wp-graphql-acf' );
			return $tabs;
		});

		// Setup the Field Settings for each field type.
		$this->setup_field_settings();

		/**
		 * Enqueue scripts to enhance the UI of the ACF Field Group Settings
		 */
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_graphql_acf_scripts' ], 10, 1 );

		/**
		 * Register meta boxes for the ACF Field Group Settings
		 */
		if ( ! defined( 'ACF_VERSION' ) || version_compare( ACF_VERSION, '6.1', '<' ) ) {
			add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		} else {
			add_action( 'acf/field_group/render_group_settings_tab/graphql', [ $this, 'display_graphql_field_group_fields' ] );
			add_filter( 'acf/field_group/additional_group_settings_tabs', static function ( $tabs ) {
				$tabs['graphql'] = __( 'GraphQL', 'wp-graphql-acf' );

				return $tabs;
			} );
		}



		/**
		 * Register an AJAX action and callback for converting ACF Location rules to GraphQL Types
		 */
		add_action( 'wp_ajax_get_acf_field_group_graphql_types', [ $this, 'graphql_types_ajax_callback' ] );

		add_filter( 'manage_acf-field-group_posts_columns', [ $this, 'wpgraphql_admin_table_column_headers' ], 11, 1 );

		add_action( 'manage_acf-field-group_posts_custom_column', [ $this, 'wpgraphql_admin_table_columns_html' ], 11, 2 );
	}

	/**
	 * Setup the Field Settings for configuring how each field should map to GraphQL
	 *
	 * @return void
	 */
	protected function setup_field_settings(): void {

		if ( ! function_exists( 'acf_get_field_types' ) ) {
			return;
		}

		// for ACF versions below 6.1, there's not field setting tabs, so we add the
		// graphql fields to each
		if ( ! defined( 'ACF_VERSION' ) || version_compare( ACF_VERSION, '6.1', '<' ) ) {
			add_action( 'acf/render_field_settings', [ $this, 'add_field_settings' ] );
		} else {

			// We want to add settings to _all_ field types
			$acf_field_types = array_keys( acf_get_field_types() );

			if ( ! empty( $acf_field_types ) ) {

				array_map( function ( $field_type ) {
					add_action( 'acf/field_group/render_field_settings_tab/graphql/type=' . $field_type, function ( $acf_field ) use ( $field_type ) {
						$this->add_field_settings( $acf_field, (string) $field_type );
					}, 10, 1 );
				}, $acf_field_types );

			}
		}

	}

	/**
	 * Handle the AJAX callback for converting ACF Location settings to GraphQL Types
	 *
	 * @return void
	 */
	public function graphql_types_ajax_callback(): void {

		if ( ! isset( $_POST['data'] ) ) {
			echo esc_html( __( 'No location rules were found', 'wp-graphql-acf' ) );

			/** @noinspection ForgottenDebugOutputInspection */
			wp_die();
		}

		$form_data           = [];
		$sanitized_post_data = wp_strip_all_tags( $_POST['data'] );

		parse_str( $sanitized_post_data, $form_data );

		if ( empty( $form_data ) || ! isset( $form_data['acf_field_group'] ) ) {
			wp_send_json( __( 'No form data.', 'wp-graphql-acf' ) );
		}

		if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $_POST['nonce'] ), 'wp_graphql_acf' ) ) {
			wp_send_json_error();
		}

		$field_group = $form_data['acf_field_group'];
		$rules       = new LocationRules( [ $field_group ] );
		$rules->determine_location_rules();

		$group_title = $field_group['title'] ?? '';
		$group_name  = $field_group['graphql_field_name'] ?? $group_title;
		$group_name  = \WPGraphQL\Utils\Utils::format_field_name( $group_name, true );

		$all_rules = $rules->get_rules();
		if ( isset( $all_rules[ $group_name ] ) ) {
			wp_send_json( [
				'graphql_types' => array_values( $all_rules[ $group_name ] ),
			] );
		}
		wp_send_json( [ 'graphql_types' => null ] );

	}

	/**
	 * Register the GraphQL Settings metabox for the ACF Field Group post type
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box( 'wp-graphql-acf-meta-box', __( 'GraphQL', 'wp-graphql-acf' ), [
			$this,
			'display_graphql_field_group_fields',
		], [ 'acf-field-group' ] );
	}


	/**
	 * Display the GraphQL Settings fields on the ACF Field Group add/edit admin page
	 *
	 * @param array|\WP_Post $field_group The Field Group being edited
	 *
	 * @return void
	 * @throws \GraphQL\Error\Error
	 * @throws \Exception
	 */
	public function display_graphql_field_group_fields( $field_group ): void {

		if ( $field_group instanceof WP_Post ) {
			$field_group = (array) $field_group;
		}

		// Render a field in the Field Group settings to allow for a Field Group to be shown in GraphQL.
		// @phpstan-ignore-next-line
		acf_render_field_wrap(
			[
				'label'        => __( 'Show in GraphQL', 'wp-graphql-acf' ),
				'instructions' => __( 'If the field group is active, and this is set to show, the fields in this group will be available in the WPGraphQL Schema based on the respective Location rules. NOTE: Changing a field "show_in_graphql" to "false" could create breaking changes for client applications already querying for this field group.', 'wp-graphql-acf' ),
				'type'         => 'true_false',
				'name'         => 'show_in_graphql',
				'prefix'       => 'acf_field_group',
				'value'        => isset( $field_group['show_in_graphql'] ) ? (bool) $field_group['show_in_graphql'] : 1,
				'ui'           => 1,
			],
			'div',
			'label',
			true
		);

		// Render a field in the Field Group settings to set the GraphQL field name for the field group.
		// @phpstan-ignore-next-line
		acf_render_field_wrap(
			[
				'label'        => __( 'GraphQL Type Name', 'wp-graphql-acf' ),
				'instructions' => __( 'The GraphQL Type name representing the field group in the GraphQL Schema. Must start with a letter. Can only contain Letters, Numbers and underscores. Best practice is to use "PascalCase" for GraphQL Types.', 'wp-graphql-acf' ),
				'type'         => 'text',
				'prefix'       => 'acf_field_group',
				'name'         => 'graphql_field_name',
				'required'     => isset( $field_group['show_in_graphql'] ) && (bool) $field_group['show_in_graphql'],
				'placeholder'  => __( 'FieldGroupTypeName', 'wp-graphql-acf' ),
				'value'        => ! empty( $field_group['graphql_field_name'] ) ? $field_group['graphql_field_name'] : '',
			],
			'div',
			'label',
			true
		);

		// @phpstan-ignore-next-line
		acf_render_field_wrap(
			[
				'label'        => __( 'Manually Set GraphQL Types for Field Group', 'wp-graphql-acf' ),
				'instructions' => __( 'By default, ACF Field groups are added to the GraphQL Schema based on the field group\'s location rules. Checking this box will let you manually control the GraphQL Types the field group should be shown on in the GraphQL Schema using the checkboxes below, and the Location Rules will no longer effect the GraphQL Types.', 'wp-graphql-acf' ),
				'type'         => 'true_false',
				'name'         => 'map_graphql_types_from_location_rules',
				'prefix'       => 'acf_field_group',
				'value'        => isset( $field_group['map_graphql_types_from_location_rules'] ) && (bool) $field_group['map_graphql_types_from_location_rules'],
				'ui'           => 1,
			],
			'div',
			'label',
			true
		);

		$choices = Utils::get_all_graphql_types();

		// @phpstan-ignore-next-line
		acf_render_field_wrap(
			[
				'label'        => __( 'GraphQL Types to Show the Field Group On', 'wp-graphql-acf' ),
				'instructions' => __( 'Select the Types in the WPGraphQL Schema to show the fields in this field group on', 'wp-graphql-acf' ),
				'type'         => 'checkbox',
				'prefix'       => 'acf_field_group',
				'name'         => 'graphql_types',
				'value'        => ! empty( $field_group['graphql_types'] ) ? $field_group['graphql_types'] : [],
				'toggle'       => true,
				'choices'      => $choices,
			],
			'div',
			'label',
			true
		);

		// Render a field in the Field Group settings to show interfaces for a Field Group to be shown in GraphQL.
		$interfaces            = $this->get_registry()->get_field_group_interfaces( $field_group );
		$field_group_type_name = $this->get_registry()->get_field_group_graphql_type_name( $field_group );


		// @phpstan-ignore-next-line
		acf_render_field_wrap(
			[
				'label'        => __( 'GraphQL Interfaces', 'wp-graphql-acf' ),
				'instructions' => sprintf( __( "These are the GraphQL Interfaces implemented by the '%s' GraphQL Type", 'wp-graphql-acf' ), $field_group_type_name ),
				'type'         => 'message',
				'name'         => 'graphql_interfaces',
				'prefix'       => 'acf_field_group',
				'message'      => ! empty( $interfaces ) ? $i = '<ul><li>' . implode( '</li><li>', $interfaces ) . '</li></ul>' : [],
				'readonly'     => true,
			],
			'div',
			'label',
			true
		);

		?>
		<div class="acf-hidden">
			<input
				type="hidden"
				name="acf_field_group[key]"
				value="<?php echo esc_attr( $field_group['key'] ); ?>"
			/>
		</div>
		<script type="text/javascript">
			if (typeof acf !== 'undefined') {
				acf.newPostbox({
					'id': 'wp-graphql-acf-meta-box',
					'label': <?php echo $this->is_acf6_or_higher ? 'top' : "'left'"; ?>
				});
			}
		</script>
		<?php

	}

	/**
	 * Add settings to each field to show in GraphQL
	 *
	 * @param array $field The field to add the setting to.
	 * @param string|null $field_type The Type of field being configured.
	 *
	 * @return void
	 */
	public function add_field_settings( array $field, ?string $field_type = null ): void {

		// We define a non-empty string for field type for ACF versions before 6.1
		if ( ! defined( 'ACF_VERSION' ) || version_compare( ACF_VERSION, '6.1', '<' ) ) {
			$field_type = '<6.1';
		}

		$field_registry = Utils::get_type_registry();
		if ( empty( $field_type ) ) {
			return;
		}

		$acf_field_type = Utils::get_graphql_field_type( $field_type );

		if ( ! $acf_field_type instanceof AcfGraphQLFieldType ) {
			$admin_field_settings = [
				'not_supported' => [
					'type'         => 'message',
					'label'        => __( 'Not supported in the GraphQL Schema', 'wp-graphql-acf' ),
					'instructions' => sprintf( __( 'The "%s" Field Type is not set up to map to the GraphQL Schema. If you want to query this field type in the Schema, visit our guide for <a href="" target="_blank" rel="nofollow">adding GraphQL support for additional ACF field types</a>.', 'wp-graphql-acf' ), $field_type ),
					'conditions'   => [],
				],
			];
		} else {
			$admin_field_settings = $acf_field_type->get_admin_field_settings( $field, $this );
		}

		if ( ! empty( $admin_field_settings ) && is_array( $admin_field_settings ) ) {

			foreach ( $admin_field_settings as $admin_field_setting_name => $admin_field_setting_config ) {

				if ( empty( $admin_field_setting_config ) || ! is_array( $admin_field_setting_config ) ) {
					continue;
				}

				$default_config = [
					'conditions' => [
						'field'    => 'show_in_graphql',
						'operator' => '==',
						'value'    => '1',
					],
					'ui'         => true,
					// used in the acf_render_field_setting below. Can be overridden per-field
					'global'     => true,
				];

				// Merge the default field setting with the passed in field setting
				$setting_field_config = array_merge( $default_config, $admin_field_setting_config );

				// @phpstan-ignore-next-line
				acf_render_field_setting( $field, $setting_field_config, (bool) $setting_field_config['global'] );
			}
		}


	}

	/**
	 * Get the config for the non_null field
	 *
	 * @param array $override Array of settings to override the default behavior
	 *
	 * @return array
	 */
	public function get_graphql_non_null_field_config( array $override = [] ): array {
		return array_merge( [
			'label'         => __( 'GraphQL NonNull?', 'wp-graphql-acf' ),
			'instructions'  => __( 'Whether the field should be non-null in the GraphQL Schema. Entries that do not have a value for this field will result in a GraphQL error. Default false, even for "required" fields, as a field can be set required after previous entries have no data entered for the field and would cause errors. Changing this value can lead to breaking changes in your GraphQL Schema.', 'wp-graphql-acf' ),
			'name'          => 'graphql_non_null',
			'key'           => 'graphql_non_null',
			'type'          => 'true_false',
			'default_value' => false,
		], $override );
	}

	/**
	 * Get the config for the non_null field
	 *
	 * @param array $override Array of settings to override the default behavior
	 *
	 * @return array
	 */
	public function get_graphql_resolve_type_field_config( array $override = [] ): array {
		return array_merge( [
			'label'         => __( 'GraphQL Resolve Type', 'wp-graphql-acf' ),
			'instructions'  => __( 'The GraphQL Type the field will show in the Schema as and resolve to.', 'wp-graphql-acf' ),
			'name'          => 'graphql_resolve_type',
			'key'           => 'graphql_resolve_type',
			'type'          => 'select',
			'multiple'      => false,
			'ui'            => false,
			'allow_null'    => false,
			'default_value' => 'list:string',
			'choices'       => [
				'string'      => 'String',
				'int'         => 'Int',
				'float'       => 'Float',
				'list:string' => '[String] (List of Strings)',
				'list:int'    => '[Int] (List of Integers)',
				'list:float'  => '[Float] (List of Floats)',
			],
		], $override );
	}

	/**
	 * This enqueues admin script.
	 *
	 * @param string $screen The screen that scripts are being enqueued to
	 *
	 * @return void
	 */
	public function enqueue_graphql_acf_scripts( string $screen ): void {
		global $post;

		if ( ! ( 'post-new.php' === $screen || 'post.php' === $screen ) ) {
			return;
		}

		if ( ! isset( $post->post_type ) || 'acf-field-group' !== $post->post_type ) {
			return;
		}

		wp_enqueue_script(
			'graphql-acf',
			plugins_url( '/assets/admin/js/main.js', __DIR__ ),
			[
				'jquery',
				'acf-input',
				'acf-field-group',
			],
			WPGRAPHQL_FOR_ACF_VERSION,
			true
		);

		wp_localize_script( 'graphql-acf', 'wp_graphql_acf', [
			'nonce' => wp_create_nonce( 'wp_graphql_acf' ),
		]);

	}

	/**
	 * Add header to the field group admin page columns showing types and interfaces
	 *
	 * @param array $_columns The column headers to add the values to.
	 *
	 * @return array The column headers with the added wp-graphql columns
	 */
	public function wpgraphql_admin_table_column_headers( array $_columns ): array {

		$columns  = [];
		$is_added = false;

		foreach ( $_columns as $name => $value ) {
			$columns[ $name ] = $value;
			// After the location column, add the wpgraphql specific columns
			if ( 'acf-location' === $name ) {
				$columns['acf-wpgraphql-type']       = __( 'GraphQL Type', 'wp-graphql-acf' );
				$columns['acf-wpgraphql-interfaces'] = __( 'GraphQL Interfaces', 'wp-graphql-acf' );
				$columns['acf-wpgraphql-locations']  = __( 'GraphQL Locations', 'wp-graphql-acf' );
				$is_added                            = true;
			}
		}
		// If not added after the specific column, add to the end of the list
		if ( ! $is_added ) {
			$columns['acf-wpgraphql-type']       = __( 'GraphQL Type', 'wp-graphql-acf' );
			$columns['acf-wpgraphql-interfaces'] = __( 'GraphQL Interfaces', 'wp-graphql-acf' );
			$columns['acf-wpgraphql-locations']  = __( 'GraphQL Locations', 'wp-graphql-acf' );
		}

		return $columns;
	}

	/**
	 * Add values to the field group admin page columns showing types and interfaces
	 *
	 * @param string $column_name The column being processed.
	 * @param int    $post_id     The field group id being processed
	 *
	 * @return void
	 * @throws \GraphQL\Error\Error
	 */
	public function wpgraphql_admin_table_columns_html( string $column_name, int $post_id ): void {
		global $field_group;

		if ( empty( $post_id ) ) {
			echo null;
		}

		// @phpstan-ignore-next-line
		$field_group = acf_get_field_group( $post_id );

		if ( empty( $field_group ) ) {
			echo null;
		}

		switch ( $column_name ) {
			case 'acf-wpgraphql-type':
				$type_name = $this->get_registry()->get_field_group_graphql_type_name( $field_group );

				// @phpstan-ignore-next-line
				echo '<span class="acf-wpgraphql-type">' . acf_esc_html( $type_name ) . '</span>';
				break;
			case 'acf-wpgraphql-interfaces':
				$interfaces = $this->get_registry()->get_field_group_interfaces( $field_group );
				$html       = Utils::array_list_by_limit( $interfaces, 5 );

				// @phpstan-ignore-next-line
				echo '<span class="acf-wpgraphql-interfaces">' . acf_esc_html( $html ) . '</span>';
				break;
			case 'acf-wpgraphql-locations':
				$acf_field_groups = $this->get_registry()->get_acf_field_groups();
				$locations        = $this->get_registry()->get_graphql_locations_for_field_group( $field_group, $acf_field_groups );
				if ( $locations ) {
					$html = Utils::array_list_by_limit( $locations, 5 );

					// @phpstan-ignore-next-line
					echo '<span class="acf-wpgraphql-location-types">' . acf_esc_html( $html ) . '</span>';
				}
				break;
			default:
				echo null;
		}
	}

}
