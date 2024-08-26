<?php
/**
 * The Applepay module.
 *
 * @package WooCommerce\PayPalCommerce\Applepay
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Applepay;

use WC_Payment_Gateway;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use WooCommerce\PayPalCommerce\Applepay\Assets\ApplePayButton;
use WooCommerce\PayPalCommerce\Applepay\Assets\AppleProductStatus;
use WooCommerce\PayPalCommerce\Applepay\Assets\PropertiesDictionary;
use WooCommerce\PayPalCommerce\Button\Assets\ButtonInterface;
use WooCommerce\PayPalCommerce\Button\Assets\SmartButtonInterface;
use WooCommerce\PayPalCommerce\Applepay\Helper\AvailabilityNotice;
use WooCommerce\PayPalCommerce\Onboarding\Environment;
use WooCommerce\PayPalCommerce\Vendor\Dhii\Container\ServiceProvider;
use WooCommerce\PayPalCommerce\Vendor\Dhii\Modular\Module\ModuleInterface;
use WooCommerce\PayPalCommerce\Vendor\Interop\Container\ServiceProviderInterface;
use WooCommerce\PayPalCommerce\Vendor\Psr\Container\ContainerInterface;
use WooCommerce\PayPalCommerce\WcGateway\Settings\Settings;

/**
 * Class ApplepayModule
 */
class ApplepayModule implements ModuleInterface {
	/**
	 * {@inheritDoc}
	 */
	public function setup(): ServiceProviderInterface {
		return new ServiceProvider(
			require __DIR__ . '/../services.php',
			require __DIR__ . '/../extensions.php'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function run( ContainerInterface $c ): void {
		$module = $this;

		// Clears product status when appropriate.
		add_action(
			'woocommerce_paypal_payments_clear_apm_product_status',
			function( Settings $settings = null ) use ( $c ): void {
				$apm_status = $c->get( 'applepay.apple-product-status' );
				assert( $apm_status instanceof AppleProductStatus );
				$apm_status->clear( $settings );
			}
		);

		add_action(
			'init',
			static function () use ( $c, $module ) {

				// Check if the module is applicable, correct country, currency, ... etc.
				if ( ! $c->get( 'applepay.eligible' ) ) {
					return;
				}

				// Load the button handler.
				$apple_payment_method = $c->get( 'applepay.button' );
				// add onboarding and referrals hooks.
				assert( $apple_payment_method instanceof ApplepayButton );
				$apple_payment_method->initialize();

				// Show notice if there are product availability issues.
				$availability_notice = $c->get( 'applepay.availability_notice' );
				assert( $availability_notice instanceof AvailabilityNotice );
				$availability_notice->execute();

				// Return if server not supported.
				if ( ! $c->get( 'applepay.server_supported' ) ) {
					return;
				}

				// Check if this merchant can activate / use the buttons.
				// We allow non referral merchants as they can potentially still use ApplePay, we just have no way of checking the capability.
				if ( ( ! $c->get( 'applepay.available' ) ) && $c->get( 'applepay.is_referral' ) ) {
					return;
				}

				if ( $apple_payment_method->is_enabled() ) {
					$module->load_assets( $c, $apple_payment_method );
					$module->handle_validation_file( $c, $apple_payment_method );
					$module->render_buttons( $c, $apple_payment_method );
					$apple_payment_method->bootstrap_ajax_request();
				}

				$module->load_admin_assets( $c, $apple_payment_method );
				$module->load_block_editor_assets( $c, $apple_payment_method );
			},
			1
		);

		add_filter(
			'nonce_user_logged_out',
			/**
			 * Prevents nonce from being changed for non logged in users.
			 *
			 * @param int $uid The uid.
			 * @param string|int $action The action.
			 * @return int
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			function ( $uid, $action ) {
				if ( $action === PropertiesDictionary::NONCE_ACTION ) {
					return 0;
				}
				return $uid;
			},
			100,
			2
		);

		add_filter(
			'woocommerce_payment_gateways',
			/**
			 * Param types removed to avoid third-party issues.
			 *
			 * @psalm-suppress MissingClosureParamType
			 */
			static function ( $methods ) use ( $c ): array {
				if ( ! is_array( $methods ) ) {
					return $methods;
				}

				$settings = $c->get( 'wcgateway.settings' );
				assert( $settings instanceof Settings );

				if ( $settings->has( 'applepay_button_enabled' ) && $settings->get( 'applepay_button_enabled' ) ) {
					$applepay_gateway = $c->get( 'applepay.wc-gateway' );
					assert( $applepay_gateway instanceof WC_Payment_Gateway );

					$methods[] = $applepay_gateway;
				}

				return $methods;
			}
		);

		add_action(
			'woocommerce_review_order_after_submit',
			function () {
				// Wrapper ID: #ppc-button-ppcp-applepay.
				echo '<div id="ppc-button-' . esc_attr( ApplePayGateway::ID ) . '"></div>';
			}
		);

		add_action(
			'woocommerce_pay_order_after_submit',
			function () {
				// Wrapper ID: #ppc-button-ppcp-applepay.
				echo '<div id="ppc-button-' . esc_attr( ApplePayGateway::ID ) . '"></div>';
			}
		);
	}

	/**
	 * Returns the key for the module.
	 *
	 * @return string|void
	 */
	public function getKey() {
	}

	/**
	 * Loads the validation string.
	 *
	 * @param boolean $is_sandbox The environment for this merchant.
	 */
	protected function load_domain_association_file( bool $is_sandbox ): void {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$request_uri = (string) filter_var( wp_unslash( $_SERVER['REQUEST_URI'] ), FILTER_SANITIZE_URL );
		if ( strpos( $request_uri, '.well-known/apple-developer-merchantid-domain-association' ) !== false ) {
			$validation_string = $this->validation_string( $is_sandbox );
			nocache_headers();
			header( 'Content-Type: text/plain', true, 200 );
			echo $validation_string;// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
	}

	/**
	 * Registers and enqueues the assets.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function load_assets( ContainerInterface $c, ApplePayButton $button ): void {
		if ( ! $button->is_enabled() ) {
			return;
		}

		add_action(
			'wp_enqueue_scripts',
			function () use ( $c, $button ) {
				$smart_button = $c->get( 'button.smart-button' );
				assert( $smart_button instanceof SmartButtonInterface );
				if ( $smart_button->should_load_ppcp_script() ) {
					$button->enqueue();
					return;
				}

				if ( has_block( 'woocommerce/checkout' ) || has_block( 'woocommerce/cart' ) ) {
					/**
					 * Should add this to the ButtonInterface.
					 *
					 * @psalm-suppress UndefinedInterfaceMethod
					 */
					$button->enqueue_styles();
				}
			}
		);
		add_action(
			'enqueue_block_editor_assets',
			function () use ( $c, $button ) {
				$button->enqueue_admin_styles();
			}
		);

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( PaymentMethodRegistry $payment_method_registry ) use ( $c ): void {
				$payment_method_registry->register( $c->get( 'applepay.blocks-payment-method' ) );
			}
		);
	}

	/**
	 * Registers and enqueues the assets.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function load_admin_assets( ContainerInterface $c, ApplePayButton $button ): void {
		// Enqueue backend scripts.
		add_action(
			'admin_enqueue_scripts',
			static function () use ( $c, $button ) {
				if ( ! is_admin() || ! $c->get( 'wcgateway.is-ppcp-settings-payment-methods-page' ) ) {
					return;
				}

				/**
				 * Should add this to the ButtonInterface.
				 *
				 * @psalm-suppress UndefinedInterfaceMethod
				 */
				$button->enqueue_admin();
				$button->enqueue_admin_styles();
			}
		);

		// Adds ApplePay component to the backend button preview settings.
		add_action(
			'woocommerce_paypal_payments_admin_gateway_settings',
			function( array $settings ) use ( $c ): array {
				if ( is_array( $settings['components'] ) ) {
					$settings['components'][] = 'applepay';
				}
				return $settings;
			}
		);
	}

	/**
	 * Enqueues the editor assets.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function load_block_editor_assets( ContainerInterface $c, ApplePayButton $button ): void {
		// Enqueue backend scripts.
		add_action(
			'enqueue_block_editor_assets',
			static function () use ( $c, $button ) {
				/**
				 * Should add this to the ButtonInterface.
				 *
				 * @psalm-suppress UndefinedInterfaceMethod
				 */
				$button->enqueue_admin_styles();
			}
		);
	}

	/**
	 * Renders the Apple Pay buttons in the enabled places.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function render_buttons( ContainerInterface $c, ApplePayButton $button ): void {
		if ( ! $button->is_enabled() ) {
			return;
		}

		add_action(
			'wp',
			static function () use ( $c ) {
				if ( is_admin() ) {
					return;
				}
				$button = $c->get( 'applepay.button' );

				/**
				 * The Button.
				 *
				 * @var ButtonInterface $button
				 */
				$button->render();
			}
		);
	}

	/**
	 * Handles the validation file.
	 *
	 * @param ContainerInterface $c The container.
	 * @param ApplePayButton     $button The button.
	 * @return void
	 */
	public function handle_validation_file( ContainerInterface $c, ApplePayButton $button ): void {
		if ( ! $button->is_enabled() ) {
			return;
		}
		$env = $c->get( 'onboarding.environment' );
		assert( $env instanceof Environment );
		$is_sandobx = $env->current_environment_is( Environment::SANDBOX );
		$this->load_domain_association_file( $is_sandobx );
	}

	/**
	 * Returns the validation string, depending on the environment.
	 *
	 * @param bool $is_sandbox The environment for this merchant.
	 * @return string
	 */
	public function validation_string( bool $is_sandbox ) : string {
		$sandbox_string = $this->sandbox_validation_string();
		$live_string    = $this->live_validation_string();
		return $is_sandbox ? $sandbox_string : $live_string;
	}

	/**
	 * Returns the sandbox validation string.
	 *
	 * @return string
	 */
	private function sandbox_validation_string(): string {
		return apply_filters(
			'woocommerce_paypal_payments_applepay_sandbox_validation_string',
			'7B227073704964223A2241443631324543383841333039314132314539434132433035304439454130353741414535444341304542413237424243333838463239344231353534434233222C2276657273696F6E223A312C22637265617465644F6E223A313731353237313630333931352C227369676E6174757265223A223330383030363039326138363438383666373064303130373032613038303330383030323031303133313064333030623036303936303836343830313635303330343032303133303830303630393261383634383836663730643031303730313030303061303830333038323033653333303832303338386130303330323031303230323038313636333463386230653330353731373330306130363038326138363438636533643034303330323330376133313265333032633036303335353034303330633235343137303730366336353230343137303730366336393633363137343639366636653230343936653734363536373732363137343639366636653230343334313230326432303437333333313236333032343036303335353034306230633164343137303730366336353230343336353732373436393636363936333631373436393666366532303431373537343638366637323639373437393331313333303131303630333535303430613063306134313730373036633635323034393665363332653331306233303039303630333535303430363133303235353533333031653137306433323334333033343332333933313337333433373332333735613137306433323339333033343332333833313337333433373332333635613330356633313235333032333036303335353034303330633163363536333633326437333664373032643632373236663662363537323264373336393637366535663535343333343264353035323466343433313134333031323036303335353034306230633062363934663533323035333739373337343635366437333331313333303131303630333535303430613063306134313730373036633635323034393665363332653331306233303039303630333535303430363133303235353533333035393330313330363037326138363438636533643032303130363038326138363438636533643033303130373033343230303034633231353737656465626436633762323231386636386464373039306131323138646337623062643666326332383364383436303935643934616634613534313162383334323065643831316633343037653833333331663163353463336637656233323230643662616435643465666634393238393839336537633066313361333832303231313330383230323064333030633036303335353164313330313031666630343032333030303330316630363033353531643233303431383330313638303134323366323439633434663933653465663237653663346636323836633366613262626664326534623330343530363038326230363031303530353037303130313034333933303337333033353036303832623036303130353035303733303031383632393638373437343730336132663266366636333733373032653631373037303663363532653633366636643266366636333733373033303334326436313730373036633635363136393633363133333330333233303832303131643036303335353164323030343832303131343330383230313130333038323031306330363039326138363438383666373633363430353031333038316665333038316333303630383262303630313035303530373032303233303831623630633831623335323635366336393631366536333635323036663665323037343638363937333230363336353732373436393636363936333631373436353230363237393230363136653739323037303631373237343739323036313733373337353664363537333230363136333633363537303734363136653633363532303666363632303734363836353230373436383635366532303631373037303663363936333631363236633635323037333734363136653634363137323634323037343635373236643733323036313665363432303633366636653634363937343639366636653733323036663636323037353733363532633230363336353732373436393636363936333631373436353230373036663663363936333739323036313665363432303633363537323734363936363639363336313734363936663665323037303732363136333734363936333635323037333734363137343635366436353665373437333265333033363036303832623036303130353035303730323031313632613638373437343730336132663266373737373737326536313730373036633635326536333666366432663633363537323734363936363639363336313734363536313735373436383666373236393734373932663330333430363033353531643166303432643330326233303239613032376130323538363233363837343734373033613266326636333732366332653631373037303663363532653633366636643266363137303730366336353631363936333631333332653633373236633330316430363033353531643065303431363034313439343537646236666435373438313836383938393736326637653537383530376537396235383234333030653036303335353164306630313031666630343034303330323037383033303066303630393261383634383836663736333634303631643034303230353030333030613036303832613836343863653364303430333032303334393030333034363032323130306336663032336362323631346262333033383838613136323938336531613933663130353666353066613738636462396261346361323431636331346532356530323231303062653363643064666431363234376636343934343735333830653964343463323238613130383930613361316463373234623862346362383838393831386263333038323032656533303832303237356130303330323031303230323038343936643266626633613938646139373330306130363038326138363438636533643034303330323330363733313162333031393036303335353034303330633132343137303730366336353230353236663666373432303433343132303264323034373333333132363330323430363033353530343062306331643431373037303663363532303433363537323734363936363639363336313734363936663665323034313735373436383666373236393734373933313133333031313036303335353034306130633061343137303730366336353230343936653633326533313062333030393036303335353034303631333032353535333330316531373064333133343330333533303336333233333334333633333330356131373064333233393330333533303336333233333334333633333330356133303761333132653330326330363033353530343033306332353431373037303663363532303431373037303663363936333631373436393666366532303439366537343635363737323631373436393666366532303433343132303264323034373333333132363330323430363033353530343062306331643431373037303663363532303433363537323734363936363639363336313734363936663665323034313735373436383666373236393734373933313133333031313036303335353034306130633061343137303730366336353230343936653633326533313062333030393036303335353034303631333032353535333330353933303133303630373261383634386365336430323031303630383261383634386365336430333031303730333432303030346630313731313834313964373634383564353161356532353831303737366538383061326566646537626165346465303864666334623933653133333536643536363562333561653232643039373736306432323465376262613038666437363137636538386362373662623636373062656338653832393834666635343435613338316637333038316634333034363036303832623036303130353035303730313031303433613330333833303336303630383262303630313035303530373330303138363261363837343734373033613266326636663633373337303265363137303730366336353265363336663664326636663633373337303330333432643631373037303663363537323666366637343633363136373333333031643036303335353164306530343136303431343233663234396334346639336534656632376536633466363238366333666132626266643265346233303066303630333535316431333031303166663034303533303033303130316666333031663036303335353164323330343138333031363830313462626230646561313538333338383961613438613939646562656264656261666461636232346162333033373036303335353164316630343330333032653330326361303261613032383836323636383734373437303361326632663633373236633265363137303730366336353265363336663664326636313730373036633635373236663666373436333631363733333265363337323663333030653036303335353164306630313031666630343034303330323031303633303130303630613261383634383836663736333634303630323065303430323035303033303061303630383261383634386365336430343033303230333637303033303634303233303361636637323833353131363939623138366662333563333536636136326266663431376564643930663735346461323865626566313963383135653432623738396638393866373962353939663938643534313064386639646539633266653032333033323264643534343231623061333035373736633564663333383362393036376664313737633263323136643936346663363732363938323132366635346638376137643162393963623962303938393231363130363939306630393932316430303030333138323031383833303832303138343032303130313330383138363330376133313265333032633036303335353034303330633235343137303730366336353230343137303730366336393633363137343639366636653230343936653734363536373732363137343639366636653230343334313230326432303437333333313236333032343036303335353034306230633164343137303730366336353230343336353732373436393636363936333631373436393666366532303431373537343638366637323639373437393331313333303131303630333535303430613063306134313730373036633635323034393665363332653331306233303039303630333535303430363133303235353533303230383136363334633862306533303537313733303062303630393630383634383031363530333034303230316130383139333330313830363039326138363438383666373064303130393033333130623036303932613836343838366637306430313037303133303163303630393261383634383836663730643031303930353331306631373064333233343330333533303339333133363332333033303333356133303238303630393261383634383836663730643031303933343331316233303139333030623036303936303836343830313635303330343032303161313061303630383261383634386365336430343033303233303266303630393261383634383836663730643031303930343331323230343230633364366466616634636131326539643331666630363161636563303536613232653131386261333262633934346664323166336231373838363634646634363330306130363038326138363438636533643034303330323034343733303435303232313030633961346263306665316537366332356636343136303264306238313462363666643264376534623263636537343138633132343532313866356230353963363032323036623632373361383536363830633738313064303131333538666463383563633764303730656531393736333234316537356336636237353732326164303930303030303030303030303030227D'
		);
	}

	/**
	 * Returns the live validation string.
	 *
	 * @return string
	 */
	private function live_validation_string(): string {
		return apply_filters(
			'woocommerce_paypal_payments_applepay_live_validation_string',
			'7B227073704964223A2246354246304143324336314131413238313043373531453439333444414433384346393037313041303935303844314133453241383436314141363232414145222C2276657273696F6E223A312C22637265617465644F6E223A313731353230343037313232362C227369676E6174757265223A223330383030363039326138363438383666373064303130373032613038303330383030323031303133313064333030623036303936303836343830313635303330343032303133303830303630393261383634383836663730643031303730313030303061303830333038323033653333303832303338386130303330323031303230323038313636333463386230653330353731373330306130363038326138363438636533643034303330323330376133313265333032633036303335353034303330633235343137303730366336353230343137303730366336393633363137343639366636653230343936653734363536373732363137343639366636653230343334313230326432303437333333313236333032343036303335353034306230633164343137303730366336353230343336353732373436393636363936333631373436393666366532303431373537343638366637323639373437393331313333303131303630333535303430613063306134313730373036633635323034393665363332653331306233303039303630333535303430363133303235353533333031653137306433323334333033343332333933313337333433373332333735613137306433323339333033343332333833313337333433373332333635613330356633313235333032333036303335353034303330633163363536333633326437333664373032643632373236663662363537323264373336393637366535663535343333343264353035323466343433313134333031323036303335353034306230633062363934663533323035333739373337343635366437333331313333303131303630333535303430613063306134313730373036633635323034393665363332653331306233303039303630333535303430363133303235353533333035393330313330363037326138363438636533643032303130363038326138363438636533643033303130373033343230303034633231353737656465626436633762323231386636386464373039306131323138646337623062643666326332383364383436303935643934616634613534313162383334323065643831316633343037653833333331663163353463336637656233323230643662616435643465666634393238393839336537633066313361333832303231313330383230323064333030633036303335353164313330313031666630343032333030303330316630363033353531643233303431383330313638303134323366323439633434663933653465663237653663346636323836633366613262626664326534623330343530363038326230363031303530353037303130313034333933303337333033353036303832623036303130353035303733303031383632393638373437343730336132663266366636333733373032653631373037303663363532653633366636643266366636333733373033303334326436313730373036633635363136393633363133333330333233303832303131643036303335353164323030343832303131343330383230313130333038323031306330363039326138363438383666373633363430353031333038316665333038316333303630383262303630313035303530373032303233303831623630633831623335323635366336393631366536333635323036663665323037343638363937333230363336353732373436393636363936333631373436353230363237393230363136653739323037303631373237343739323036313733373337353664363537333230363136333633363537303734363136653633363532303666363632303734363836353230373436383635366532303631373037303663363936333631363236633635323037333734363136653634363137323634323037343635373236643733323036313665363432303633366636653634363937343639366636653733323036663636323037353733363532633230363336353732373436393636363936333631373436353230373036663663363936333739323036313665363432303633363537323734363936363639363336313734363936663665323037303732363136333734363936333635323037333734363137343635366436353665373437333265333033363036303832623036303130353035303730323031313632613638373437343730336132663266373737373737326536313730373036633635326536333666366432663633363537323734363936363639363336313734363536313735373436383666373236393734373932663330333430363033353531643166303432643330326233303239613032376130323538363233363837343734373033613266326636333732366332653631373037303663363532653633366636643266363137303730366336353631363936333631333332653633373236633330316430363033353531643065303431363034313439343537646236666435373438313836383938393736326637653537383530376537396235383234333030653036303335353164306630313031666630343034303330323037383033303066303630393261383634383836663736333634303631643034303230353030333030613036303832613836343863653364303430333032303334393030333034363032323130306336663032336362323631346262333033383838613136323938336531613933663130353666353066613738636462396261346361323431636331346532356530323231303062653363643064666431363234376636343934343735333830653964343463323238613130383930613361316463373234623862346362383838393831386263333038323032656533303832303237356130303330323031303230323038343936643266626633613938646139373330306130363038326138363438636533643034303330323330363733313162333031393036303335353034303330633132343137303730366336353230353236663666373432303433343132303264323034373333333132363330323430363033353530343062306331643431373037303663363532303433363537323734363936363639363336313734363936663665323034313735373436383666373236393734373933313133333031313036303335353034306130633061343137303730366336353230343936653633326533313062333030393036303335353034303631333032353535333330316531373064333133343330333533303336333233333334333633333330356131373064333233393330333533303336333233333334333633333330356133303761333132653330326330363033353530343033306332353431373037303663363532303431373037303663363936333631373436393666366532303439366537343635363737323631373436393666366532303433343132303264323034373333333132363330323430363033353530343062306331643431373037303663363532303433363537323734363936363639363336313734363936663665323034313735373436383666373236393734373933313133333031313036303335353034306130633061343137303730366336353230343936653633326533313062333030393036303335353034303631333032353535333330353933303133303630373261383634386365336430323031303630383261383634386365336430333031303730333432303030346630313731313834313964373634383564353161356532353831303737366538383061326566646537626165346465303864666334623933653133333536643536363562333561653232643039373736306432323465376262613038666437363137636538386362373662623636373062656338653832393834666635343435613338316637333038316634333034363036303832623036303130353035303730313031303433613330333833303336303630383262303630313035303530373330303138363261363837343734373033613266326636663633373337303265363137303730366336353265363336663664326636663633373337303330333432643631373037303663363537323666366637343633363136373333333031643036303335353164306530343136303431343233663234396334346639336534656632376536633466363238366333666132626266643265346233303066303630333535316431333031303166663034303533303033303130316666333031663036303335353164323330343138333031363830313462626230646561313538333338383961613438613939646562656264656261666461636232346162333033373036303335353164316630343330333032653330326361303261613032383836323636383734373437303361326632663633373236633265363137303730366336353265363336663664326636313730373036633635373236663666373436333631363733333265363337323663333030653036303335353164306630313031666630343034303330323031303633303130303630613261383634383836663736333634303630323065303430323035303033303061303630383261383634386365336430343033303230333637303033303634303233303361636637323833353131363939623138366662333563333536636136326266663431376564643930663735346461323865626566313963383135653432623738396638393866373962353939663938643534313064386639646539633266653032333033323264643534343231623061333035373736633564663333383362393036376664313737633263323136643936346663363732363938323132366635346638376137643162393963623962303938393231363130363939306630393932316430303030333138323031383833303832303138343032303130313330383138363330376133313265333032633036303335353034303330633235343137303730366336353230343137303730366336393633363137343639366636653230343936653734363536373732363137343639366636653230343334313230326432303437333333313236333032343036303335353034306230633164343137303730366336353230343336353732373436393636363936333631373436393666366532303431373537343638366637323639373437393331313333303131303630333535303430613063306134313730373036633635323034393665363332653331306233303039303630333535303430363133303235353533303230383136363334633862306533303537313733303062303630393630383634383031363530333034303230316130383139333330313830363039326138363438383666373064303130393033333130623036303932613836343838366637306430313037303133303163303630393261383634383836663730643031303930353331306631373064333233343330333533303338333233313333333433333331356133303238303630393261383634383836663730643031303933343331316233303139333030623036303936303836343830313635303330343032303161313061303630383261383634386365336430343033303233303266303630393261383634383836663730643031303930343331323230343230353936643939343335373738303366313137346361653066633761343164383634653964366266336535363638646164356563393334303937313439633762623330306130363038326138363438636533643034303330323034343733303435303232313030383565623363643837343731346466343461333830373838643439626537656630303630643765313236633966653638663261333336386363623233373363643032323035366535336363363330376433393561643465663532376234333531323462616636653761383537363030616463376135343561333862613039376139643734303030303030303030303030227D'
		);
	}
}
