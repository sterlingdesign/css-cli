<?php
declare(strict_types=1);
namespace Sterling\StackTools;

use GetOpt\GetOpt;

class SassWatcherOptions extends GetOpt
{
//-------------------------------------------------------------------------------------------------
public function __construct()
{
  parent::__construct();

  try
    {
    $optVersion = new \GetOpt\Option('v', 'version');
    $optVersion->setDescription('Display version number and exit');

    $optHelp = new \GetOpt\Option('h', 'help');
    $optHelp->setDescription('Display this usage information and exit');

    $optGen = new \GetOpt\Option('g', 'generate-dart');
    $optGen->setDescription('Generate a list of directories compatible with dart-sass cli and exit without any other processing');

    /*
    $optRerun = new \GetOpt\Option('r', 'rerun', \GetOpt\GetOpt::NO_ARGUMENT);
    $optRerun->setDescription('Re-Run css post sass tool against all sass generated files regardless of timestamps');
    $optRerun->setDefaultValue(false);
    */

    $optPretty = new \GetOpt\Option('p', 'pretty-print', GetOpt::NO_ARGUMENT);
    $optPretty->setDescription('Run the css post sass tool with the pretty-print flag set');

    $optKeepMaps = new \GetOpt\Option('m', 'keepmaps', GetOpt::NO_ARGUMENT);
    $optKeepMaps->setDescription('Instruct dart to generate mapps and the post-dart processor to keep them');

    $optWatch = new \GetOpt\Option('w', 'watch');
    $optWatch->setDescription('Monitor the sass output files and run css tools when modified');

    $optImmediate = new \GetOpt\Option('i', 'immediate');
    $optImmediate->setDescription('Run SASS and PostProcessors immediately on startup.  All detected files will be updated.');

    $optStackDir = new \GetOpt\Option('s', 'sterling-stack', GetOpt::MULTIPLE_ARGUMENT);
    $optStackDir->setDescription('A directory that is the root directory of a sterling stack.  Sass source and output folders will be automatically detected');
    $oStackDirArg = new \GetOpt\Argument('', function($value){return is_dir($value);});
    $optStackDir->setArgument($oStackDirArg);

    //$getopt = new \GetOpt\GetOpt([$optVersion, $optHelp, $optRerun, $optPretty, $optWatch, $optStackDir]);
    $this->addOptions([$optVersion, $optHelp, $optGen, $optPretty, $optKeepMaps, $optImmediate, $optWatch, $optStackDir]);

    // TESTING
    //var_dump($this->getOption('ConfigFile'));
    //CliInfo("Operand Count: " . count($this->getOperands()));
    //CliInfo("Operands: " . print_r($this->getOperands(), true));
    //CliInfo("--sterling-stack Value: " . print_r($this->getOption('s'), true));

    }
  catch(\Throwable $throwable)
    {
    CliError($throwable->getMessage());
    }
}
//-------------------------------------------------------------------------------------------------
public function processArgv() : bool
{
  try
    {
    // the process function gets the arguments from $_SERVER["argv"] by default.
    // throws various errors if there are problems
    parent::process();
    // if there were no problems, we get here and return true
    return true;
    }
  catch(\Throwable $throwable)
    {
    CliError($throwable->getMessage());
    }
  return false;
}
//-------------------------------------------------------------------------------------------------
  function showVersion()
  {
    $strPackageJsonFile = __DIR__ . '/../composer.json';
    if(file_exists($strPackageJsonFile))
      {
      $oPkg = json_decode(file_get_contents($strPackageJsonFile));
      if(property_exists($oPkg, "version"))
        $strVersion = strval($oPkg->version);
      else
        $strVersion = "no version found in " . $strPackageJsonFile;
      }
    else
      $strVersion = ("file not found: " . $strPackageJsonFile);
    echo $strVersion . PHP_EOL;
  }
//-------------------------------------------------------------------------------------------------
  function showHelp()
  {
    echo $this->getHelpText();
  }
//-------------------------------------------------------------------------------------------------
  function showHelpInvalidOperand()
  {
    CliError("Invalid directory operand");
    CliInfo("Directory Operands must be in the form \"path/to/sass/folder:path/to/output/folder");
    echo $this->getHelpText();
  }
//-------------------------------------------------------------------------------------------------
  function showHelpMissingDirectories()
  {
    echo $this->getHelpText();
    CliError("No Directories were given.");
    CliInfo("You must provide one or more sass input:output directories to operate on, either as a command line argument or
 through the --sterling-stack option argument.");
  }

}