<?php
declare(strict_types=1);

const BOOTSTRAP_AUTOLOAD_PHP = __DIR__ . "/bootstrap.php";
require_once BOOTSTRAP_AUTOLOAD_PHP;

use Sterling\StackTools\SassDirectories;
use Sterling\StackTools\MainWatchLoop;
use Sterling\StackTools\DartProcessor;
use Sterling\StackTools\PostSassProcessor;
use Sterling\StackTools\SassWatcherOptions;


//-------------------------------------------------------------------------------------------------
$g_Options = new SassWatcherOptions();
exit(Main());

//-------------------------------------------------------------------------------------------------
function Main() : int
{
  global $g_Options;
  $iReturn = -1;
  if($g_Options->processArgv())
    {
    $iReturn = 0;
    if($g_Options->getOption('h'))
      {
      $g_Options->showHelp();
      }
    else if($g_Options->getOption('v'))
      {
      $g_Options->showVersion();
      }
    else if(count($g_Options->getOperands()) == 0 && empty($g_Options->getOption('s')))
      {
      $iReturn = -2;
      $g_Options->showHelpMissingDirectories();
      }
    else
      {
      $oSassDirs = SassDirectories::buildDirectories($g_Options->getOperands(), $g_Options->getOption('s'));
      if(!is_object($oSassDirs))
        {
        CliError("Quitting due to previous errors.");
        $iReturn = -3;
        }
      else if(0 == $oSassDirs->GetSassDirCount())
        {
        CliError("Quitting because no valid sass directories were specified");
        $iReturn = -4;
        }
      else if($g_Options->getOption('g'))
        {
        writeInfoText(DartProcessor::GetDartCmd(boolval($g_Options->getOption('w')), boolval($g_Options->getOption('p')), !boolval($g_Options->getOption('m'))));
        echo $oSassDirs->GetDartSassDiretoryList() . PHP_EOL;
        }
      else if($g_Options->getOption('w') || $g_Options->getOption('i'))
        {
        if($g_Options->getOption('i'))
          {
          $iReturn = (MainWatchLoop::RunImmediate($oSassDirs, boolval($g_Options->getOption('p')), !boolval($g_Options->getOption('m'))) ? 0 : -1);
          }
        if($g_Options->getOption('w'))
          {
          $oWatchLoop = new MainWatchLoop($oSassDirs);
          $iReturn = $oWatchLoop->run();
          }
        }
      else
        {
        $g_Options->showHelp();
        CliWarning("No commands were specified.  Exiting.");
        }
      }
    }

  return $iReturn;
}
