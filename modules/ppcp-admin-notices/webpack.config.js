const path = require( 'path' );
const isProduction = process.env.NODE_ENV === 'production';

module.exports = {
	devtool: isProduction ? 'source-map' : 'eval-source-map',
	mode: isProduction ? 'production' : 'development',
	target: 'web',
	entry: {
		'boot-admin': path.resolve( './resources/js/boot-admin.js' ),
        "styles": path.resolve('./resources/css/styles.scss')
	},
	output: {
		path: path.resolve( __dirname, 'assets/' ),
		filename: 'js/[name].js',
	},
	module: {
		rules: [
			{
				test: /\.js?$/,
				exclude: /node_modules/,
				loader: 'babel-loader',
			},
			{
				test: /\.scss$/,
				exclude: /node_modules/,
				use: [
					{
						loader: 'file-loader',
						options: {
							name: 'css/[name].css',
						},
					},
					{ loader: 'sass-loader' },
				],
			},
		],
	},
};
