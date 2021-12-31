#!/usr/bin/env node

//-------------------------------------------------------------------------------------------------
// -- File System (built-in)
// https://nodejs.org/api/fs.html
// See also fs-extra: https://www.npmjs.com/package/fs-extra
const fs = require('fs');
//-------------------------------------------------------------------------------------------------
// -- Path (built-in)
// https://nodejs.org/api/path.htm
const path = require('path');
//-------------------------------------------------------------------------------------------------
// -- Command line argument handling:
// https://www.npmjs.com/package/minimist
const parseArgs = require('minimist');
//-------------------------------------------------------------------------------------------------
// -- Console colorization:
// https://github.com/alexeyraspopov/picocolors#readme
const pc = require('picocolors');
//-------------------------------------------------------------------------------------------------
// -- Settings Information:
// the parameter used by autoprefixer to select the browser support level
// is stored in the package.json file.  Load it as an object
// so we can look at it and get values:
const pkg = require('./package.json');
//-------------------------------------------------------------------------------------------------
// -- PostCSS seems to be required to run autoprefixer
// It's only *real* job is to load the CSS into a tree and pass that tree along to whatever
// plugin(s) you choose to add to it. It seems there are some other hidden things postcss
// provides to autoprefixer as well, so we're stuck using it instead of running
// autoprefixer directly.
// https://github.com/postcss/postcss
// Here, we only need the postcss function, which takes an array of plugins and
// returns a Processor class object.
const postcss = require("postcss");
//-------------------------------------------------------------------------------------------------
// -- Running Autoprefixer is one of the main objectives for this project, so that you don't
// have to deal with the vendor prefixes and can write clean W3C based styles.
// https://github.com/postcss/autoprefixer#readme
// NOTE: autoprefixer uses the 'browserlist' package (which uses the caniuse-lite database).
// It's important to know how the browser selection parameter works, and also
// important to update the caniuse-lite database periodically by running
// 'npm update caniuse-lite' from this project's real root folder
// https://www.npmjs.com/package/browserslist
const autoprefixer = require("autoprefixer");
//-------------------------------------------------------------------------------------------------
// -- Clean and Minify CSS
// There are several available, however this one merges @media queries when
// configured for level 2.  It also has the pretty-print option so that after
// doing all the clean-up and merging, you can still get readable output for
// inspection.
// https://github.com/clean-css/clean-css#formatting-options
// https://www.npmjs.com/package/clean-css#formatting-options
const CleanCSS = require('clean-css');
// NOTE: the clean-css docs recommend using the postcss-clean module and running
// that through postcss.  That would be great, except that the postcss-clean module
// requires async behavior, which we are not doing here: only synchronous behavior for
// this command line utility since the calling process can not continue until this is
// complete.
//const PostcssClean = require('postcss-clean');
//-------------------------------------------------------------------------------------------------
// -- Lint
// why not check the result and see if lint finds anything wrong?
// https://github.com/stylelint/stylelint/blob/main/docs/user-guide/usage/postcss-plugin.md
// NOT IMPLEMENTED YET

//-------------------------------------------------------------------------------------------------
function unknownOption(opt)
{
  if((opt[0] === '-') && (!fs.existsSync(path.resolve(opt))))
    {
    CliError("Unknown command line option: '" + opt + "'");
    process.exit(-1);
    }
   return true;
}
//-------------------------------------------------------------------------------------------------
const argv = parseArgs(process.argv.slice(2), opt={_: [], 'string': ['browsers'],'boolean': ['p','pretty','m','keepmaps','v','version','h','help','t','test','cleanlevel0','cleanlevel1','cleanlevel2'],'--': true,'unknown': unknownOption});
//const argv = parseArgs(process.argv.slice(2), opt={'boolean': ['p','pretty','v','version','h','help','t','test','cleanlevel0','cleanlevel1','cleanlevel2'],'--': true});

const g_bPrettyPrint = argv.pretty || argv.p;
const g_bKeepMaps = argv.keepmaps || argv.m;
const g_bVersion = argv.version || argv.v;
const g_bHelp = argv.help || argv.h;
const g_bTest = argv.test || argv.t;
const g_iCleanLevel = (argv.cleanlevel0 ? 0 : (argv.cleanlevel1 ? 1 : (argv.cleanlevel2 ? 2 : 2)));
const g_arBrowserList = (isString(argv.browsers) && argv.browsers.length > 0 ? makeBrowserListFromString(argv.browsers) : pkg.browserslist);

const strUsage = `
  Usage: ${pkg.name} [-((pt) | v | h)] ["file"|"directory"]*

    -p, --pretty        Pretty print result so it can be examined.
                        default is to strip all space.
    -m, --keepmaps      Keep Maps if they were generated in the original
                        CSS
    --browsers="list"   Specify a browser list to use with autoprefix.
                        The default is ${pkg.browserslist}
    --cleanlevel0       what level to run clean-css at.
    --cleanlevel1       default clean level is 2.
    --cleanlevel2
    -v, --version       show version and info about this utility
                        script and exit
    -h, --help          Show this usage screen and exit
    -t, --test          run autoprefix/clean and display the result.
                        A default sample is supplied if no file is supplied.
                        If more than one file is supplied, only the first
                        one is used.
`;

const g_strInternalSample = `
@charset "UTF-8";

.testFlowClass {
  display: flow;
}

.testFlex {
  display: flex;
  flex-flow: row nowrap;
  flex-grow: 1;
}

.testGrid {
  display: grid;
}

/* Autoprefixer should remove the -webkit-border-radius for most modern browsers */
.testBorder {
  -webkit-border-radius: 4px;
  border-radius: 4px;
  border: 1px solid red;
}

.cTestA, .cTestB {display:none;}

@media (min-width: 600px) {
  .cTestA{display: block;}
}

@media (min-width: 600px) {
  .cTestB{display: block;}
}

`;

//-------------------------------------------------------------------------------------------------
process.exit(main());

//-------------------------------------------------------------------------------------------------
function main()
{
  let iResult = 0;
  try
    {
    if(g_bHelp)
      {
      usage();
      }
    else if(g_bVersion)
      {
      version_info();
      }
    else if(g_bTest)
      {
      test();
      }
    else if(!isArray(argv._) || argv._.length <= 0)
      {
      CliWarning("No files specified");
      iResult = -1;
      usage();
      }
    else
      {
      processArgs();
      }
    }
  catch (e)
    {
    CliError(e.toString());
    iResult = -1;
    }

  return iResult;
}
//-------------------------------------------------------------------------------------------------
function processArgs()
{
  for(let i = 0; i < argv._.length; i++)
    {
    let strFile = path.resolve(argv._[i].toString()).toString();
    let strContents = file_get_contents(strFile);
    if(isString(strContents))
      {
      let strResult = processCSS(strContents);
      if(isString(strResult) && strResult.length > 0)
        {
        if(file_put_contents(strFile, strResult))
          {
          CliProcSuccess(strFile);
          }
        else
          {
          CliProcFailure(strFile, "Could not write result file");
          }
        }
      else
        {
        CliProcFailure(strFile, "Bad result, see errors");
        }
      }
    else
      {
      CliProcFailure(strFile, "Could not read source file");
      }
    }
}
//-------------------------------------------------------------------------------------------------
function CliProcFailure(strFile, strReason)
{
  let strResult = pc.red("FAILED: " + strFile) + pc.yellow("[" + strReason + "]");
  CliProcResult(strResult);
}
//-------------------------------------------------------------------------------------------------
function CliProcSuccess(strFile)
{
  let strResult = pc.green("PROCESSED: " + strFile);
  CliProcResult(strResult);
}
//-------------------------------------------------------------------------------------------------
function CliProcResult(strResult)
{
  console.log(strResult + pc.cyan("[" + new Date().getTime().toString() + "]"));
}
//-------------------------------------------------------------------------------------------------
function test()
{
  let strSample = g_strInternalSample;
  let strTestSource = "Internal Sample";

  console.log("");
  console.log(pc.inverse(pc.green(` ${pkg.name} - Testing - `)));
  console.log("");

  // if a file was specified, load that file instead
  if(isArray(argv._) && argv._.length > 0)
    {
    let strFile = path.resolve(argv._[0].toString());
    strSample = file_get_contents(strFile);
    if(isString(strSample))
      strTestSource = strFile;
    else
      return;
    }

  printKeyValuePair("Browsers", g_arBrowserList);
  printKeyValuePair("Clean Level", g_iCleanLevel.toString());
  printKeyValuePair("Pretty Print", "" + (g_bPrettyPrint ? "true" : "false"));
  printKeyValuePair("Test Source", strTestSource);

  console.log("");
  console.log(pc.inverse(pc.cyan("---------- Sample CSC ----------")));
  console.log(strSample);
  console.log(pc.inverse(pc.cyan("------------ Result ------------")));
  let strResult = processCSS(strSample);
  console.log(strResult);

}
//-------------------------------------------------------------------------------------------------
function file_get_contents(strFile)
{
  const iMAX_TRIES = 2;
  const iRETRY_MS = 1500;
  let iTries = 0;

  if(fs.existsSync(strFile) && !fs.lstatSync(strFile).isDirectory())
    {
    // if the file is still open exclusively by another process, readFileSync (is supposed to)
    // throw an error.  Here, we can try waiting for iRETRY_MS milliseconds to allow the
    // other process to finish writing the file:
    while(iTries++ < iMAX_TRIES)
      {
      try
        {
        return fs.readFileSync(strFile, {encoding: 'UTF-8'});
        }
      catch (e)
        {
        CliError("Could not read the file: " + e.toString());
        if(iTries < iMAX_TRIES)
          {
          CliError("Retrying in " + iRETRY_MS + " milliseconds...")
          sleep(iRETRY_MS);
          }
        }
      }
    }
  else
    {
    if(fs.existsSync(strFile) && fs.lstatSync(strFile).isDirectory())
      CliError("The path '" + strFile + "' is a DIRECTORY, not a file");
    else
      CliError("The file '" + strFile + "' does not exist");
    }

  return false;
}
//-------------------------------------------------------------------------------------------------
function file_put_contents(strFile, strData)
{
  const iMAX_TRIES = 2;
  const iRETRY_MS = 1500;
  let iTries = 0;
  while(iTries++ < iMAX_TRIES)
    {
    try
      {
      fs.writeFileSync(strFile, strData);
      break;
      }
    catch (e)
      {
      CliError("Failed to write '" + strFile + "' - " + e);
      if(iTries < iMAX_TRIES)
        {
        CliError("Retrying in " + iRETRY_MS + " milliseconds...")
        sleep(iRETRY_MS);
        }
      }
    }
  return (iTries <= iMAX_TRIES);
}
//-------------------------------------------------------------------------------------------------
/**
 *
 * @param strBuf string
 * @return string|null
 */
function processCSS(strBuf)
{
  let strResult = null;
  try
    {
    // First of all, test for dart's error output which starts with a comment:
    if(strBuf.trimLeft().substr(0, 9) === "/* Error:")
      {
      let strErr = '';
      const lines = strBuf.split(/\r\n|\n/);
      for(let i = 0; i < lines.length && lines[i].length; i++)
        strErr += lines[i] + "\n";
      console.log(strErr);
      return null;
      }

    let optAutoprefixer = getAutoprefixerOptions();
    let oAutoprefixResult =  postcss([autoprefixer(optAutoprefixer)]).process(strBuf);
    // oAutoprefixResult is asynchronous, but the call below "toString()" seems to
    // force completion synchronously:
    strResult = oAutoprefixResult.toString();
    if(strResult)
      {
      let optClean = getCleanLevelOptions();
      let oCleanedResult = new CleanCSS(optClean).minify(strResult);
      if(oCleanedResult && oCleanedResult.styles)
        strResult = oCleanedResult.styles.toString();
      else
        {
        strResult = null;
        CliError("Unexpected result from clean-css");
        }
      }
    else
      {
      strResult = null;
      CliError("Unexpected result from autoprefixer (result dump follows)");
      console.error(oAutoprefixResult);
      }
    }
  catch (e)
    {
    CliError(e.toString());
    strResult = null;
    }
  return strResult;
}
//-------------------------------------------------------------------------------------------------
function version_info()
{
  console.log('');
  console.log(`    ${pc.green(pkg.name)} - version and information`);
  console.log('');
  printKeyValuePair("Script Version           ",  pkg.version);
  printKeyValuePair("Script Location          ", __dirname);
  printKeyValuePair("Current Working Directory", process.cwd());
  printKeyValuePair("Node Version             ", process.versions.node);
  printKeyValuePair("Node Executable          ", process.execPath);
}
//-------------------------------------------------------------------------------------------------
function printKeyValuePair(strKey, strVal)
{
  console.log(`    ${pc.cyan(strKey)}: ${strVal}`);
}
//-------------------------------------------------------------------------------------------------
function CliInfo(strMsg)
{
  console.log(pc.green(strMsg));
}
//-------------------------------------------------------------------------------------------------
function CliWarning(strMsg)
{
  console.warn(pc.yellow("WARNING") + ": " + strMsg);
}
//-------------------------------------------------------------------------------------------------
function CliError(strMsg)
{
  console.error(pc.red("ERROR") + ": " + strMsg);
  // EOL is NOT automatically appended when stdout/stderr write functions are used:
  // process.stdout.write("I will goto the STDOUT")
  // process.stderr.write("I will goto the STDERR")
}
//-------------------------------------------------------------------------------------------------
function usage()
{
  console.log(pc.green(strUsage));
}
//-------------------------------------------------------------------------------------------------
/*

Function autoprefixer(options) returns a new PostCSS plugin. See PostCSS API for plugin usage documentation.

autoprefixer({ cascade: false })
Available options are:

env (string): environment for Browserslist.
cascade (boolean): should Autoprefixer use Visual Cascade, if CSS is uncompressed. Default: true
add (boolean): should Autoprefixer add prefixes. Default is true.
remove (boolean): should Autoprefixer [remove outdated] prefixes. Default is true.
supports (boolean): should Autoprefixer add prefixes for @supports parameters. Default is true.
flexbox (boolean|string): should Autoprefixer add prefixes for flexbox properties. With "no-2009" value Autoprefixer will add prefixes only for final and IE 10 versions of specification. Default is true.
grid (false|"autoplace"|"no-autoplace"): should Autoprefixer add IE 10-11 prefixes for Grid Layout properties?
  false (default): prevent Autoprefixer from outputting CSS Grid translations.
  "autoplace": enable Autoprefixer grid translations and include autoplacement support. You can also use [css comment start]autoprefixer grid: autoplace [css comment end] in your CSS.
  "no-autoplace": enable Autoprefixer grid translations but exclude autoplacement support. You can also use [css comment start] autoprefixer grid: no-autoplace [css comment end] in your CSS. (alias for the deprecated true value)
stats (object): custom usage statistics for > 10% in my stats browsers query.
overrideBrowserslist (array): list of queries for target browsers. Try to not use it. The best practice is to use .browserslistrc config or browserslist key in package.json to share target browsers with Babel, ESLint and Stylelint. See Browserslist docs for available queries and default value.
ignoreUnknownVersions (boolean): do not raise error on unknown browser version in Browserslist config. Default is false.

  Plugin object has info() method for debugging purpose.

  You can use PostCSS processor to process several CSS files to increase performance.
*/
function getAutoprefixerOptions()
{
  return {overrideBrowserslist: g_arBrowserList};
}
//-------------------------------------------------------------------------------------------------
function makeBrowserListFromString(strList)
{
  return strList.split(',');
}
//-------------------------------------------------------------------------------------------------
function getCleanLevelOptions()
{
  // TO DO: what are the option values needed to preserve or remove maps?
  //  It looks like 'specialComments' needs to be changed, but to what??
  //  Note: the output from dart uses /*# {map} */, but clean-css only respects /*! {special comment} */
  //   so it will take a hack somewhere to make preservation of maps work with dart output.
  //   In the future, we may decide to use a different (php based) cleaning/compressor library, so
  //   at this time we'll leave it as-is (maps generated by dart won't be preserved)

  switch (g_iCleanLevel)
    {
    case 0:
      {
      optClean = { }
      break;
      }
    case 1:
      {
      optClean = {
        level: {
          1: {
            cleanupCharsets: true, // controls `@charset` moving to the front of a stylesheet; defaults to `true`
            normalizeUrls: true, // controls URL normalization; defaults to `true`
            optimizeBackground: true, // controls `background` property optimizations; defaults to `true`
            optimizeBorderRadius: true, // controls `border-radius` property optimizations; defaults to `true`
            optimizeFilter: true, // controls `filter` property optimizations; defaults to `true`
            optimizeFont: true, // controls `font` property optimizations; defaults to `true`
            optimizeFontWeight: true, // controls `font-weight` property optimizations; defaults to `true`
            optimizeOutline: true, // controls `outline` property optimizations; defaults to `true`
            removeEmpty: true, // controls removing empty rules and nested blocks; defaults to `true`
            removeNegativePaddings: true, // controls removing negative paddings; defaults to `true`
            removeQuotes: true, // controls removing quotes when unnecessary; defaults to `true`
            removeWhitespace: true, // controls removing unused whitespace; defaults to `true`
            replaceMultipleZeros: true, // contols removing redundant zeros; defaults to `true`
            replaceTimeUnits: true, // controls replacing time units with shorter values; defaults to `true`
            replaceZeroUnits: true, // controls replacing zero values with units; defaults to `true`
            roundingPrecision: false, // rounds pixel values to `N` decimal places; `false` disables rounding; defaults to `false`
            selectorsSortingMethod: 'standard', // denotes selector sorting method; can be `'natural'` or `'standard'`, `'none'`, or false (the last two since 4.1.0); defaults to `'standard'`
            specialComments: 'all', // denotes a number of /*! ... */ comments preserved; defaults to `all`
            tidyAtRules: true, // controls at-rules (e.g. `@charset`, `@import`) optimizing; defaults to `true`
            tidyBlockScopes: true, // controls block scopes (e.g. `@media`) optimizing; defaults to `true`
            tidySelectors: true, // controls selectors optimizing; defaults to `true`
          }
        }
      }
      break;
    }
    default:
    case 2:
      {
      optClean = {
        level: {
          2: {
            cleanupCharsets: true, // controls `@charset` moving to the front of a stylesheet; defaults to `true`
            normalizeUrls: true, // controls URL normalization; defaults to `true`
            optimizeBackground: true, // controls `background` property optimizations; defaults to `true`
            optimizeBorderRadius: true, // controls `border-radius` property optimizations; defaults to `true`
            optimizeFilter: true, // controls `filter` property optimizations; defaults to `true`
            optimizeFont: true, // controls `font` property optimizations; defaults to `true`
            optimizeFontWeight: true, // controls `font-weight` property optimizations; defaults to `true`
            optimizeOutline: true, // controls `outline` property optimizations; defaults to `true`
            removeEmpty: true, // controls removing empty rules and nested blocks; defaults to `true`
            removeNegativePaddings: true, // controls removing negative paddings; defaults to `true`
            removeQuotes: true, // controls removing quotes when unnecessary; defaults to `true`
            removeWhitespace: true, // controls removing unused whitespace; defaults to `true`
            replaceMultipleZeros: true, // contols removing redundant zeros; defaults to `true`
            replaceTimeUnits: true, // controls replacing time units with shorter values; defaults to `true`
            replaceZeroUnits: true, // controls replacing zero values with units; defaults to `true`
            roundingPrecision: false, // rounds pixel values to `N` decimal places; `false` disables rounding; defaults to `false`
            selectorsSortingMethod: 'standard', // denotes selector sorting method; can be `'natural'` or `'standard'`, `'none'`, or false (the last two since 4.1.0); defaults to `'standard'`
            specialComments: 'all', // denotes a number of /*! ... */ comments preserved; defaults to `all`
            tidyAtRules: true, // controls at-rules (e.g. `@charset`, `@import`) optimizing; defaults to `true`
            tidyBlockScopes: true, // controls block scopes (e.g. `@media`) optimizing; defaults to `true`
            tidySelectors: true, // controls selectors optimizing; defaults to `true`
            mergeAdjacentRules: true, // controls adjacent rules merging; defaults to true
            mergeIntoShorthands: true, // controls merging properties into shorthands; defaults to true
            mergeMedia: true, // controls `@media` merging; defaults to true
            mergeNonAdjacentRules: true, // controls non-adjacent rule merging; defaults to true
            mergeSemantically: false, // controls semantic merging; defaults to false
            overrideProperties: true, // controls property overriding based on understandability; defaults to true
            reduceNonAdjacentRules: true, // controls non-adjacent rule reducing; defaults to true
            removeDuplicateFontRules: true, // controls duplicate `@font-face` removing; defaults to true
            removeDuplicateMediaBlocks: true, // controls duplicate `@media` removing; defaults to true
            removeDuplicateRules: true, // controls duplicate rules removing; defaults to true
            removeUnusedAtRules: false, // controls unused at rule removing; defaults to false (available since 4.1.0)
            restructureRules: false, // controls rule restructuring; defaults to false
            skipProperties: [] // controls which properties won't be optimized, defaults to `[]` which means all will be optimized (since 4.1.0)
          }
        }
      }
      break;
      }
    }

  if(g_bPrettyPrint)
    optClean.format = 'beautify';

  return optClean;
}

//-------------------------------------------------------------------------------------------------
function isArray(a)
{
  return (!!a) && (a.constructor === Array);
}
//-------------------------------------------------------------------------------------------------
function isObject(o)
{
  return (!!a) && (a.constructor === Object);
}
//-------------------------------------------------------------------------------------------------
function isString(s)
{
  return (typeof s === 'string' || s instanceof String);
}
//-------------------------------------------------------------------------------------------------
function sleep(millis) {
  let unixtime_ms = new Date().getTime();
  while(new Date().getTime() < unixtime_ms + millis) {}
}

