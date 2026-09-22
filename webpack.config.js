const path = require('path');
const fs = require('fs');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const CssMinimizerPlugin = require('css-minimizer-webpack-plugin');

module.exports = {
  mode: 'production',
  entry: {
    styles: './src/styles/index.css',
    gallery: './src/index.jsx',
  },
  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: '[name].min.js',
    clean: true,
  },
  resolve: {
    extensions: ['.jsx', '.js', '.json'],
    alias: {
      '@components': path.resolve(__dirname, 'src/components'),
      '@utils': path.resolve(__dirname, 'src/utils'),
      '@hooks': path.resolve(__dirname, 'src/hooks'),
    },
  },
  module: {
    rules: [
      {
        test: /\.(jsx?)$/i,
        exclude: /node_modules/,
        use: {
          loader: 'babel-loader',
          options: {
            presets: [
              ['@babel/preset-env', { targets: { browsers: ['last 2 versions'] } }],
              ['@babel/preset-react', { runtime: 'automatic' }],
            ],
          },
        },
      },
      {
        test: /\.css$/i,
        use: [
          MiniCssExtractPlugin.loader,
          {
            loader: 'css-loader',
            options: {
              importLoaders: 1,
            },
          },
        ],
      },
    ],
  },
  plugins: [
    new MiniCssExtractPlugin({
      filename: '[name].min.css',
    }),
    // Copy service worker to dist folder
    {
      apply: (compiler) => {
        compiler.hooks.afterEmit.tap('CopyServiceWorker', () => {
          const src = path.join(__dirname, 'src', 'service-worker.js');
          const dest = path.join(__dirname, 'dist', 'service-worker.js');
          
          if (fs.existsSync(src)) {
            fs.copyFileSync(src, dest);
            console.log('Service Worker copied to dist/');
          }
        });
      }
    },
  ],
  optimization: {
    minimize: true,
    minimizer: [
      // JavaScript minification
      new TerserPlugin({
        terserOptions: {
          compress: {
            drop_console: false,  // Keep console.logs for debugging
          },
          output: {
            comments: false,
          },
        },
        extractComments: false,
      }),
      // CSS minification
      new CssMinimizerPlugin({
        minimizerOptions: {
          preset: [
            'default',
            {
              discardComments: { removeAll: true },
            },
          ],
        },
      }),
    ],
  },
};
