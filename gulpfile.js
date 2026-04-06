// Project Specific Variables
const projectPath 		= './templates/';
const devPath 			= projectPath + '_dev';
const buildPath 		= './web';
const projectURL 		= 'http://local.neuroscience';

// npm packages
const gulp = require('gulp');
const gulpLoadPlugins = require('gulp-load-plugins');
const cp = require("child_process");
const rename = require('gulp-rename');
const newer = require('gulp-newer');
const path = require('path');
const sass = require('gulp-sass')(require('sass'));
const autoprefixer = require('gulp-autoprefixer');
const cleanCSS = require('gulp-clean-css');
const concat = require('gulp-concat');
const uglify = require('gulp-uglify');
const imagemin = require('gulp-imagemin');
const pngquant = require('imagemin-pngquant');
const browsersync = require("browser-sync").create();

const webpack = require("webpack");
const webpackconfig = require("./webpack.config.js");
const webpackstream = require("webpack-stream");

const del = require("del");
const $ = gulpLoadPlugins();


// BrowserSync
function browserSync(done) {
	console.log('browser sync');
	browsersync.init({
		proxy: projectURL,
		open: false,
		injectChanges: true,
		port: 3000,
		files: [buildPath + '/css/*.css', buildPath + '/js/*.js', projectPath + '*.php']
	});
	done();
}
// BrowserSync Reload
function browserSyncReload(done) {
  browsersync.reload();
  done();
}
// Clean assets
function clean() {
  return del([buildPath + '/css']);
}

function css() {
  console.log('Compiling CSS...');
  return gulp
    .src([devPath + '/scss/compile.scss'])
	.pipe(rename({ basename: "main" }))
    .pipe(sass({includePaths: ['node_modules/']}).on('error', sass.logError))
    .pipe(autoprefixer({ cascade: false }))
	//.pipe(cleanCSS())
    .pipe(gulp.dest(buildPath + '/css/'))
	.pipe(browsersync.stream());
}

function scripts() {
	console.log('Scripts...');
	return (
		gulp
			.src([devPath + '/js/main.js'])
			//.pipe(plumber())
			.pipe(webpackstream(webpackconfig, webpack))
			//.pipe(uglify())
			// folder only, filename is specified in webpack config
			.pipe(gulp.dest(buildPath + '/js/'))
			.pipe(browsersync.stream())
	);
}

function images() {
	return gulp
	.src([devPath + '/images/**/*.{png,jpg,gif,ico,svg}'])
	.pipe(newer(buildPath + '/images/'))
	.pipe(imagemin({
		progressive: true,
		use: [pngquant()]
	}))
	.pipe(gulp.dest(buildPath + '/images/'))
}

function watchFiles() {
	// css();
	// scripts();
	// images();
	gulp.watch(devPath + '/scss/**/*.scss', css, browserSync);
	gulp.watch(
	    [
	      devPath + "/js/**/*"
	    ],
	    gulp.series(scripts, browserSyncReload)
	);
	gulp.watch(devPath + '/images/**/*', images);
	gulp.watch(projectPath + '**/*.html', browserSyncReload);
	console.log('watching...');
}

const watch = gulp.parallel(watchFiles, browserSync);

gulp.task('default', watch);

exports.css = css;
exports.scripts = scripts;
exports.images = images;
exports.clean = clean;
exports.watch = watch;
exports.watchFiles = watchFiles;
