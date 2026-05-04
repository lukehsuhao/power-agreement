/**
 * Custom webpack config that builds on @wordpress/scripts default and
 * teaches it about the runtime-provided @woocommerce/* packages.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const requestToHandle = ( request ) => {
	const map = {
		'@woocommerce/blocks-checkout': 'wc-blocks-checkout',
		'@woocommerce/blocks-checkout-utils': 'wc-blocks-checkout',
		'@woocommerce/settings': 'wc-settings',
	};
	return map[ request ];
};

const requestToExternal = ( request ) => {
	const map = {
		'@woocommerce/blocks-checkout': [ 'wc', 'blocksCheckout' ],
		'@woocommerce/blocks-checkout-utils': [ 'wc', 'blocksCheckout' ],
		'@woocommerce/settings': [ 'wc', 'wcSettings' ],
	};
	return map[ request ];
};

module.exports = {
	...defaultConfig,
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			injectPolyfill: true,
			requestToHandle,
			requestToExternal,
		} ),
	],
};
