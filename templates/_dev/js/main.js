'use strict';

//require("jquery")
require('./modules/slick');

const App = require('./modules/app.js');
const Viewport = require('./modules/viewport.js');
const ClickFunctions = require('./modules/clickFunction.js');
const ScrollTo = require('./modules/scrollTo.js');
const Tabs = require('./modules/tabs.js');
const Filter = require('./modules/filter.js');
const ProductFilter = require('./modules/productFilter.js');
const Carousel = require('./modules/carousel.js');
const Form = require('./modules/form.js');
//const Acids = require('./modules/acids.js');
const Graphs = require('./modules/graphs.js');

$(function(){
	//create the app.
	let app = new App();
	let viewport = new Viewport();
	let clickFunctions = new ClickFunctions();
	let scrollTo = new ScrollTo();
	let tabs = new Tabs();
	let filter = new Filter();
	let productFilter = new ProductFilter();
	let carousel = new Carousel();
	let form = new Form();
	//let acids = new Acids();
	let graphs = new Graphs();

});
