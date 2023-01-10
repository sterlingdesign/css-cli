<?php
declare(strict_types=1);
use Sterling\StackTools\DartProcessor;
use Sterling\StackTools\PostSassProcessor;
use Sterling\StackTools\SassDirectories;
use parallel\{Channel, Events, Events\Event\Type, Runtime};
use Sterling\StackTools\UserCommandTask;

const BOOTSTRAP_AUTOLOAD_PHP = __DIR__ . "/bootstrap.php";
require_once BOOTSTRAP_AUTOLOAD_PHP;

//-------------------------------------------------------------------------------------------------
$g_Options = new \Sterling\StackTools\SassWatcherOptions();
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
      $oSassDirs = buildDirectories();
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
        $iReturn = 0;
        }
      else
        {
        $oProcessor = new Sterling\StackTools\PostSassProcessor($oSassDirs, boolval($g_Options->getOption('p')), boolval($g_Options->getOption('m')));
        if(!$oProcessor->ProcessAllFiles())
          {
          $iReturn = -1;
          CliError("Exiting due to previous unrecoverable errors");
          }
        else if($g_Options->getOption('w'))
          RunWatchLoop($oProcessor);
        }
      }
    }

  return $iReturn;
}
//-------------------------------------------------------------------------------------------------
// TO DO: this "RunWatchLoop" function should be written as a class so that the various events can be
// handled in separate functions (member variables would be accessible in the member functions)
//
function RunWatchLoop(PostSassProcessor $oPostSassProcessor)
{
  global $g_Options;
  $oDartProcessor = new DartProcessor();
  $oUserCmdTask = new UserCommandTask();
  $bNoSourceMaps = !boolval($g_Options->getOption('m'));
  $oEvents = new Events();
  $oEvents->addFuture($oDartProcessor->GetFutureName(), $oDartProcessor->Start([$oPostSassProcessor->GetDartSassDiretoryList(), $bNoSourceMaps]));
  $oEvents->addFuture($oPostSassProcessor->GetFutureName(), $oPostSassProcessor->Start());
  $oEvents->addFuture($oUserCmdTask->GetFutureName(), $oUserCmdTask->Start());
  $oEvents->addChannel($oUserCmdTask->GetChannel());
  $bContinue = true;

  while($bContinue)
    {
    // any event that happens except for a user command entry will cause termination.
    $bContinue = false;
    if($oEvent = $oEvents->poll())
      {
      switch($oEvent->type)
        {
        // A channel was closed
        case Type::Close:
          {
          CliWarning("The Channel for '$oEvent->source' was closed.  Quitting.");
          break;
          }
        // Event::object was read into Event::$value
        case Type::Read:
          {
          if($oEvent->source === $oUserCmdTask->GetChannelName())
            {
            $cmd = mb_strtolower(substr(strval($oEvent->value), 0, 32));
            if($cmd !== 'q' && $cmd !== 'quit')
              {
              $bContinue = true;
              if(strlen($cmd))
                CliWarning("Unknown Command '$oEvent->value'");
              }
            if($bContinue && $oUserCmdTask->IsRunning() && $oUserCmdTask->GetChannel())
              {
              try
                {
                $oEvents->addChannel($oUserCmdTask->GetChannel());
                }
              catch(\Throwable $throwable)
                {
                CliError($throwable->getMessage());
                CliWarning("Quitting due to previous error.");
                $bContinue = false;
                }
              }
            else if($bContinue)
              {
              CliWarning("Exiting because the User Command Task has stopped.");
              $bContinue = false;
              }
            if(!$bContinue)
              $oUserCmdTask->Stop();
            }
          else
            // any other source is unexpected, most likely an exited thread has thrown an error
            // or otherwise written it's Future return value
            {
            CliError("Unexpected Termination.  " . strval($oEvent->source) . " wrote: " . substr(strval($oEvent->value), 0, 128));
            $bContinue = false;
            }
          break;
          }
        // Input for Event::$source written to Event::$object
        case Type::Write:
          {
          CliWarning("Unexpected Write event from '$oEvent->source'.  Quitting.");
          break;
          }
        // Event::$object (Future) was cancelled
        case Type::Cancel:
        // Event::$object (Future) raised error
        case Type::Error:
        // Runtime executing Event::$object (Future) was killed
        case Type::Kill:
        default:
          {
          CliWarning("Process '$oEvent->source' unexpectedly ended by Error, Cancel or Kill.");
          break;
          }
        }
      }
    }

  // Removing the events is not strictly necessary, but during testing this worked fine
  RemoveEvent($oEvents, $oUserCmdTask->GetFutureName());
  RemoveEvent($oEvents, $oUserCmdTask->GetChannelName());
  RemoveEvent($oEvents, $oPostSassProcessor->GetFutureName());
  RemoveEvent($oEvents, $oDartProcessor->GetFutureName());

  $oDartProcessor->Stop();
  $oPostSassProcessor->Stop();
  if($oUserCmdTask->IsRunning())
    echo "Press the enter key to continue ";
  $oUserCmdTask->Stop(0);
}
//-------------------------------------------------------------------------------------------------
function RemoveEvent(Events $oEvents, string $strName, bool $bQuite = true)
{
  try
    {
    $oEvents->remove($strName);
    }
  catch(\Throwable $throwable)
    {
    if(!$bQuite)
      CliError($throwable->getMessage());
    }
}
//-------------------------------------------------------------------------------------------------
function buildDirectories() : ?SassDirectories
{
  global $g_Options;
  $oSassDirs = new Sterling\StackTools\SassDirectories();
  // the AddDirectories method displays errors as they occur and returns false
  // if a command line directory doesn't exist, we will consider that a fatal error
  $arOperands = $g_Options->getOperands();
  if(is_array($arOperands) && count($arOperands) > 0)
    {
    CliInfo("Adding " . count($arOperands) . " standalone (non-stack) directories");
    if(!$oSassDirs->AddDirectories($arOperands))
      {
      return null;
      }
    }
  else
    {
    CliInfo("No Standalone (non-stack) Directories Specified");
    }

  $arStackDirs = $g_Options->getOption('s');
  if(is_array($arStackDirs) && count($arStackDirs) > 0)
    {
    CliInfo("Adding " . count($arStackDirs) . " Stack Directories");
    if(0 == $oSassDirs->AddStackDirs($arStackDirs))
      CliWarning("Stack directories were specified, but no valid sass sub folders were found");
    }
  else
    {
    CliInfo("No Stack Directories Specified");
    }
  return $oSassDirs;
}
//-------------------------------------------------------------------------------------------------
