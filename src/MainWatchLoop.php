<?php
declare(strict_types=1);
namespace Sterling\StackTools;

use SevenEcks\Ansi\Colorize;
use Sterling\StackTools\PostSassProcessor;
use Sterling\StackTools\SassDirectories;
use Sterling\StackTools\DartProcessor;
use Sterling\StackTools\UserCommandTask;
use parallel\
{Events, Events\Event\Type, Runtime, Sync\Error};
use parallel\Channel;

// Like the name implies, this class implements a loop that runs on the main thread
// and handles various events
class MainWatchLoop
{
  protected bool $m_bNoSourceMaps = true;
  protected bool $m_bPrettyPrintOutput = true;
  protected ?SassDirectories $m_oSassDirs = null;

  protected ?DartProcessor $m_oDartProcessor = null;
  protected ?PostSassProcessor $m_oPostSassProcessor = null;
  protected ?UserCommandTask $m_oUserCmdTask = null;

  protected ?Events $m_oEvents = null;
  protected array $arCliInfo = [];

  const CLI_NAME = "CSS-CLI TOOL";
  //-----------------------------------------------------------------------------------------------
  public function __construct(SassDirectories $oSassDirs)
  {
    global $g_Options;
    $this->m_bNoSourceMaps = !boolval($g_Options->getOption('m'));
    $this->m_bPrettyPrintOutput = boolval($g_Options->getOption('p'));
    $this->m_oSassDirs = clone $oSassDirs;

    // Here, the Events loop should block on polling:
    $this->m_oEvents = new Events();
    $this->m_oEvents->setBlocking(true);

    $strComposerJsonFile = sdMakeFilePath(STACK_TOOLS_ROOT, "../composer.json");
    if(file_exists($strComposerJsonFile)) {
    $this->arCliInfo = json_decode(file_get_contents($strComposerJsonFile), true);

    }
  }
  //-----------------------------------------------------------------------------------------------
  // This should only be called from the main thread:
  // it waits and loops until an exit condition
  public function run(): int
  {
    $bContinue = true;
    $iReturn = 0;
    if(!$this->RestartWatchers(false, false))
      {
      CliError("Exiting due to failure of watcher threads.");
      return -1;
      }
    $this->StartUserCmdTask();
    sleep(1);
    self::PrintMenu();

    while($bContinue)
      {
      // any event that happens except for a user command entry will cause termination.
      $bContinue = false;
      if($oEvent = $this->m_oEvents->poll())
        {
        //CliDebug("Caught Event from " . $oEvent->source);
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
            if(is_object($this->m_oUserCmdTask) && ($oEvent->source === $this->m_oUserCmdTask->GetChannelName()))
              {
              $bContinue = $this->HandleUserCommand(strval($oEvent->value));
              if($bContinue)
                {
                // need to re-add the channel to the Events loop because it is removed after firing the event
                $this->m_oEvents->addChannel($this->m_oUserCmdTask->GetChannel());
                // tell the user command thread to continue
                $this->m_oUserCmdTask->GetChannel()->send('go');
                }
              else if($this->m_oUserCmdTask->IsRunning())
                {
                $this->m_oUserCmdTask->GetChannel()->send('stop');
                //CliWarning("Exiting because the User Command Task has stopped.");
                //$bContinue = false;
                //$iReturn = -1;
                }
              }
            else
              // any other source is unexpected, most likely an exited thread has thrown an error
              // or otherwise written it's Future return value
              {
              CliError("Unexpected Read Event: " . strval($oEvent->source) . " wrote: " . substr(strval($oEvent->value), 0, 128));
              $bContinue = false;
              $iReturn = -1;
              }
            break;
            }
          // Input for Event::$source written to Event::$object
          case Type::Write:
            {
            CliWarning("Unexpected Write event from '$oEvent->source'.  Quitting.");
            $iReturn = -1;
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
            $iReturn = -1;
            break;
            }
          }
        }
      }

    $this->StopSassTask();
    $this->StopPostCssTask();
    $this->StopUserCmdTask();

    return $iReturn;
  }
//-------------------------------------------------------------------------------------------------
protected static function RemoveTaskEvents(Events $oEvents, SimpleParallelThread $oTask, bool $bCloseChannel = false) : void
{
  self::RemoveEvent($oEvents, $oTask->GetFutureName());
  self::RemoveEvent($oEvents, $oTask->GetChannelName());
  if($bCloseChannel)
    $oTask->GetChannel()->close();
}
//-------------------------------------------------------------------------------------------------
public static function RemoveEvent(Events $oEvents, string $strName, bool $bQuite = true): void
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
  function HandleUserCommand(string $strCmd) : bool
  {
    $bContinue = true;
    $bPrintFullMenu = false;
    $strCmd = mb_strtolower(trim($strCmd, ' '));
    //$arCmd = explode(' ', $strCmd);
    //if(!isset($arCmd[0]))
      //$arCmd[0] = '';
    try
      {
      switch($strCmd)
        {
        case 'q':
        case 'quit':
        case 'exit':
          {
          $bContinue = false;
          break;
          }
        case '?':
        case 'h':
        case 'help':
        {
        $bPrintFullMenu = true;;
        break;
        }
        case 'i':
        case 'immediate':
        {
        $this->StopSassTask();
        $this->StopPostCssTask();
        $bContinue = $this->RestartWatchers(true, true);
        break;
        }
        case 'cls':
        case 'clear':
        {
        echo chr(27).chr(91).'H'.chr(27).chr(91).'J';   //^[H^[J
        /*
        if(IsWindowsOS())
          system('cls');
        else
          system('clear');
        */
        break;
        }
        case 'r':
        case 'restart':
        {
        $bContinue = $this->RestartWatchers(true, false);
        break;
        }
        case 'c':
        case 'compress':
        {
        if($this->m_bPrettyPrintOutput)
          {
          $this->m_bPrettyPrintOutput = false;
          $bContinue = $this->RestartWatchers(true, true);
          if($bContinue)
            CliWarning("Compression Was Turned On");
          }
        else
          CliWarning("Compression Is Already On");
        break;
        }
        case 'p':
        case 'prettyprint':
          {
          if($this->m_bPrettyPrintOutput)
            CliWarning("Compression Is Already Off");
          else
            {
            $this->m_bPrettyPrintOutput = true;
            $bContinue = $this->RestartWatchers(true, true);
            if($bContinue)
              CliWarning("Compression Was Turned Off");
            }
          break;
          }
        case 'm':
        case 'mapfiles':
        {
        if($this->m_bNoSourceMaps)
          {
          $this->m_bNoSourceMaps = false;
          $bContinue = $this->RestartWatchers(true, true);
          if($bContinue)
            CliWarning("Map File Generation Was Turned On");
          }
        else
          {
          CliWarning("Map File Generation Is Already On");
          }
        break;
        }
        case 'n':
        case 'nomapfiles':
          {
          if($this->m_bNoSourceMaps)
            {
            CliWarning("Map File Generation Was Already Off");
            }
          else
            {
            $this->m_bNoSourceMaps = true;
            $bContinue = $this->RestartWatchers(true, false);
            if($bContinue)
              CliWarning("Map File Generation Was Turned Off");
            }
          break;
          }
        case 's':
        case 'show config':
        case 'show configuration':
        {
        $this->DisplayConfiguration();
        break;
        }
        case 'show css':
          {
          $this->ShowCssStatus();
          break;
          }
        case 'show mapfiles':
          {
          $this->ShowMapfileStatus();
          break;
          }
        case 'delete css':
          {
          $this->DeleteGeneratedCss();
          break;
          }
        case 'delete mapfiles':
          {
          $this->DeleteGeneratedMapFiles();
          break;
          }
        case 'delete all':
          {
          $this->DeleteGeneratedCss();
          $this->DeleteGeneratedMapFiles();
          break;
          }
        case 'make debug':
          {
          $this->m_bPrettyPrintOutput = true;
          $this->m_bNoSourceMaps = false;
          $bContinue = $this->RestartWatchers(true, true, true, true);
          break;
          }
        case 'make release':
          {
          $this->m_bPrettyPrintOutput = false;
          $this->m_bNoSourceMaps = true;
          $bContinue = $this->RestartWatchers(true, true, true, true);
          break;
          }
        case 'version':
          {
          echo ($this->arCliInfo['name'] ?? 'unknown/unknown');
          echo ' ';
          echo ($this->arCliInfo['version'] ?? 'unknown version - composer.json missing or invalid');
          echo PHP_EOL;
          break;
          }
        case 'about':
          {
          echo PHP_EOL . Colorize::purple(self::CLI_NAME) . PHP_EOL;
          echo Colorize::cyan("About") . PHP_EOL;
          echo "Package Name: " . ($this->arCliInfo['name'] ?? 'unknown/unknown') . PHP_EOL;
          echo "Version: " . ($this->arCliInfo['version'] ?? 'unknown version - composer.json missing or invalid') . PHP_EOL;
          echo "Description: " . ($this->arCliInfo['description'] ?? 'unknown') . PHP_EOL;
          echo "Author: " . ($this->arCliInfo['authors'][0]['name'] ?? 'unknown') . PHP_EOL;
          echo "Author Email: " . ($this->arCliInfo['authors'][0]['email'] ?? 'unknown') . PHP_EOL;
          echo "License: " . ($this->arCliInfo['license'] ?? "unknown") . PHP_EOL;
          break;
          }
        default:
          {
          if(strlen($strCmd))
            CliWarning("Unknown Command: $strCmd");
          //$bPrintFullMenu = true;
          break;
          }
        }
      }
    catch(\Throwable $throwable)
      {
      CliError($throwable->getMessage());
      CliWarning("Quitting due to previous unexpected error.");
      $bContinue = false;
      }
    if($bContinue)
      self::PrintMenu($bPrintFullMenu);

    return $bContinue;
  }
  //-----------------------------------------------------------------------------------------------
  public static function PrintMenu(bool $bFullMenu = false) : void
  {
    if($bFullMenu)
      {
      echo Colorize::purple(PHP_EOL . self::CLI_NAME . PHP_EOL);
      echo Colorize::cyan("COMMAND OPTIONS HELP" . PHP_EOL);
      echo "Type command and press Enter to execute:" . PHP_EOL . PHP_EOL;
      echo "about            - display package information" . PHP_EOL;
      echo "version          - display package name and version" . PHP_EOL;
      echo "q[uit]           - shut down watchers and exit the CLI" . PHP_EOL;
      echo "h[elp]           - display this help message" . PHP_EOL;
      echo "r[estart]        - rescan directories and restart watchers" . PHP_EOL;
      echo "i[mmediate]      - immediately run sass and postcss on all files" . PHP_EOL;
      echo "c[ompress]       - compress (minify) output files" . PHP_EOL;
      echo "p[rettyprint]    - prettyprint output files for ease of inspection" . PHP_EOL;
      echo "m[apfiles]       - map files for development and debugging" . PHP_EOL;
      echo "n[omapfiles]     - no map files will be generated" . PHP_EOL;
      echo "s[how config]    - show current configuration details" . PHP_EOL;
      echo "show css         - show generated css file status" . PHP_EOL;
      echo "show mapfiles    - show generated map file status" . PHP_EOL;
      echo "delete css       - delete generated css files from target directories" . PHP_EOL;
      echo "delete mapfiles  - delete generated map files from target directories" . PHP_EOL;
      echo "delete all       - delete all generated files from target directories" . PHP_EOL;
      echo "make debug       - shortcut to refresh mapfiles and regenerate uncompressed" . PHP_EOL;
      echo "make release     - shortcut to delete mapfiles and regenerate compressed output" . PHP_EOL;
      echo PHP_EOL;
      }
    else
      {
      echo PHP_EOL . "Type 'q' to quit, 'h' for full help menu" . PHP_EOL;
      }

  }
  //-----------------------------------------------------------------------------------------------
  protected function DeleteGeneratedMapFiles() : void
  {
    $i = 0;
    $arExpectedCss = $this->m_oSassDirs->getAllExpectedOutputFiles();
    try
      {
      foreach($arExpectedCss as $css)
        {
        $css .= ".map";
        if(file_exists($css))
          $i += unlink($css) ? 1 : 0;
        }
      }
    catch(\Throwable $throwable)
      {
      CliError($throwable->getMessage());
      }
    CliWarning("Removed $i of " . count($arExpectedCss) . " Generated Map Files");
  }

  //-----------------------------------------------------------------------------------------------
  protected function DeleteGeneratedCss() : void
  {
    $i = 0;
    $arExpectedCss = $this->m_oSassDirs->getAllExpectedOutputFiles();
    try
      {
      foreach($arExpectedCss as $css)
        {
        if(file_exists($css))
          $i += unlink($css) ? 1 : 0;
        }
      }
    catch(\Throwable $throwable)
      {
      CliError($throwable->getMessage());
      }
    CliWarning("Removed $i of " . count($arExpectedCss) . " Generated CSS files");
  }
  //-----------------------------------------------------------------------------------------------
  protected function ShowCssStatus() : void
  {
    $arExpectedCss = $this->m_oSassDirs->getAllExpectedOutputFiles();
    echo Colorize::purple(PHP_EOL . self::CLI_NAME . PHP_EOL);
    echo Colorize::cyan("LIST OF GENERATED CSS" . PHP_EOL);
    echo Colorize::lightCyan("Empty brackets means the file was not found or is inaccessible") . PHP_EOL;
    echo Colorize::lightCyan(date('[Y-m-d H:i:s]', time()) . " <= Current time for comparisson (timezone is ". date_default_timezone_get() . ")") . PHP_EOL;
    echo PHP_EOL;
    if(count($arExpectedCss) == 0)
      echo Colorize::red("No CSS files are Expected Now...");
    else
      clearstatcache();

    foreach($arExpectedCss as $css)
      {
      $t = ((file_exists($css) && is_file($css)) ? filemtime($css) : false);
      //                                                     [2020-00-00 00:00:00]
      $strT = (is_numeric($t) ? date('[Y-m-d H:i:s]', $t) : '[                   ]');
      echo Colorize::cyan($strT) . ' ' . $css . PHP_EOL;
      }
  }
  //-----------------------------------------------------------------------------------------------
  protected function ShowMapfileStatus() : void
  {
    $arExpectedCss = $this->m_oSassDirs->getAllExpectedOutputFiles();
    echo Colorize::purple(PHP_EOL . self::CLI_NAME . PHP_EOL);
    echo Colorize::cyan("LIST OF MAPFILES" . PHP_EOL);
    echo Colorize::lightCyan("Empty brackets means the file was not found or is inaccessible") . PHP_EOL;
    echo Colorize::lightCyan(date('[Y-m-d H:i:s]', time()) . " <= Current time for comparisson (timezone is ". date_default_timezone_get() . ")") . PHP_EOL;
    echo PHP_EOL;
    if(count($arExpectedCss) == 0)
      echo Colorize::red("No CSS files are Expected Now...");
    else
      clearstatcache();

    foreach($arExpectedCss as $css)
      {
      $css .= ".map";
      $t = ((file_exists($css) && is_file($css)) ? filemtime($css) : false);
      //                                                     [2020-00-00 00:00:00]
      $strT = (is_numeric($t) ? date('[Y-m-d H:i:s]', $t) : '[                   ]');
      echo Colorize::cyan($strT) . ' ' . $css . PHP_EOL;
      }
  }
  //-----------------------------------------------------------------------------------------------
  protected function DisplayConfiguration() : void
  {
    echo Colorize::purple(PHP_EOL . self::CLI_NAME . PHP_EOL);
    echo Colorize::cyan("CONFIGURATION") . PHP_EOL;
    echo PHP_EOL;
    echo "Compressed (minified) Output :    " . ($this->m_bPrettyPrintOutput ? Colorize::red("Pretty-Print") : Colorize::green("Minified")) . PHP_EOL;
    echo "Generation of Map Files:          " . ($this->m_bNoSourceMaps ? Colorize::green("OFF") : Colorize::red("ON")) . PHP_EOL;
    echo "Number of Directories Watched:    " . Colorize::yellow($this->m_oSassDirs->GetSassDirCount()) . PHP_EOL;
    echo "Expected number of Generated CSS: " . Colorize::yellow(count($this->m_oSassDirs->getAllExpectedOutputFiles())) . PHP_EOL;
    echo Colorize::lightCyan("--- Sass Input Directory List ---") . PHP_EOL;
    $arSassDirs = $this->m_oSassDirs->GetAllSassDirs();
    foreach($arSassDirs as $dir)
      echo "  $dir" . PHP_EOL;
    echo Colorize::lightCyan("--- End Of Status Report ---") . PHP_EOL;
    
  }
  //-----------------------------------------------------------------------------------------------
  protected function RestartWatchers(bool $bRescanSassDirs, bool $bRunImmediate, bool $bDeleteCss = false, bool $bDeleteMapfiles = false) : bool
  {
    $this->StopSassTask();
    $this->StopPostCssTask();
    if($bRescanSassDirs)
      $this->RebuildSassDirs();
    if(0 == $this->m_oSassDirs->GetSassDirCount())
      {
      CliWarning("There are no more sass directories for this process to watch.  Exiting.");
      $bContinue = false;
      }
    else
      {
      if($bDeleteCss)
        $this->DeleteGeneratedCss();
      if($bDeleteMapfiles)
        $this->DeleteGeneratedMapfiles();
      if($bRunImmediate)
        self::RunImmediate($this->m_oSassDirs, $this->m_bPrettyPrintOutput, $this->m_bNoSourceMaps);
      $bContinue = ($this->StartPostCssTask() && $this->StartSassTask());
      if($bContinue)
        usleep(100000);
      }
    return $bContinue;
  }
  //-----------------------------------------------------------------------------------------------
  protected function RebuildSassDirs() : void
  {
    global $g_Options;
    $this->m_oSassDirs = SassDirectories::buildDirectories($g_Options->getOperands(), $g_Options->getOption('s'));
  }
  //-----------------------------------------------------------------------------------------------
  protected function StartPostCssTask() : bool
  {
    $this->StopPostCssTask();
    $this->m_oPostSassProcessor = new PostSassProcessor();
    $this->m_oEvents->addFuture($this->m_oPostSassProcessor->GetFutureName(), $this->m_oPostSassProcessor->Start([$this->m_oPostSassProcessor->GetChannel(), $this->m_oSassDirs->GetAllDirectoryPairs(), $this->m_bPrettyPrintOutput, !$this->m_bNoSourceMaps]));
    $this->m_oEvents->addChannel($this->m_oPostSassProcessor->GetChannel());
    return $this->m_oPostSassProcessor->IsRunning();
  }
  //-----------------------------------------------------------------------------------------------
  protected function StopPostCssTask() : void
  {
    if(is_object($this->m_oPostSassProcessor))
      {
      self::RemoveTaskEvents($this->m_oEvents, $this->m_oPostSassProcessor, true);
      if($this->m_oPostSassProcessor->IsRunning())
        $this->m_oPostSassProcessor->Stop();
      }
    $this->m_oPostSassProcessor = null;
  }
  //-----------------------------------------------------------------------------------------------
  protected function StartSassTask() : bool
  {
    $this->StopSassTask();
    $this->m_oDartProcessor = new DartProcessor();
    $this->m_oEvents->addFuture($this->m_oDartProcessor->GetFutureName(), $this->m_oDartProcessor->Start([$this->m_oDartProcessor->GetChannel(), $this->m_oSassDirs->GetDartSassDiretoryList(), $this->m_bNoSourceMaps]));
    $this->m_oEvents->addChannel($this->m_oDartProcessor->GetChannel());
    return $this->m_oDartProcessor->IsRunning();
  }
  //-----------------------------------------------------------------------------------------------
  protected function StopSassTask(): void
  {
    if(is_object($this->m_oDartProcessor))
      {
      self::RemoveTaskEvents($this->m_oEvents, $this->m_oDartProcessor, true);
      if($this->m_oDartProcessor->IsRunning())
        $this->m_oDartProcessor->Stop();
      }
    $this->m_oDartProcessor = null;
  }
  //-----------------------------------------------------------------------------------------------
  protected function StartUserCmdTask() : bool
  {
    $this->StopUserCmdTask();
    $this->m_oUserCmdTask = new UserCommandTask();
    $this->m_oEvents->addFuture($this->m_oUserCmdTask->GetFutureName(), $this->m_oUserCmdTask->Start([$this->m_oUserCmdTask->GetChannel()]));
    $this->m_oEvents->addChannel($this->m_oUserCmdTask->GetChannel());
    return $this->m_oUserCmdTask->IsRunning();
  }
  //-----------------------------------------------------------------------------------------------
  protected function StopUserCmdTask() : void
  {
    if(is_object($this->m_oUserCmdTask))
      {
      self::RemoveTaskEvents($this->m_oEvents, $this->m_oUserCmdTask, true);
      if($this->m_oUserCmdTask->IsRunning())
        echo "Press the enter key to continue ";
      $this->m_oUserCmdTask->Stop();
      }
    $this->m_oUserCmdTask = null;
  }
  //-----------------------------------------------------------------------------------------------
  static function RunImmediate(SassDirectories $oSassDirs, bool $bPrettyPrint, bool $bNoSourceMaps) : bool
  {
    // Dart needs to run without the 'w' option so that it compiles
    //  everything and exits immediately
    $strDartCmd = DartProcessor::GetDartCmd(false, $bPrettyPrint, $bNoSourceMaps);
    $strDartCmd .= " --no-stop-on-error";
    $strDartCmd .= " " . $oSassDirs->GetDartSassDiretoryList();
    //echo $strDartCmd . PHP_EOL;
    $arOutput = [];
    $oSassDirs->InitializeOutputStats();
    $strLastLine = exec($strDartCmd, $arOutput, $iReturn);
    if(is_array($arOutput))
      echo implode(PHP_EOL, $arOutput);
    else
      echo "The command did not return the expected value";
    writeInfoText(PHP_EOL . "sass exited with return code " . strval($iReturn) . PHP_EOL);
    $arFiles = $oSassDirs->GetModifiedFiles();
    echo strval(count($arFiles)) . " File(s) were modified by sass" . PHP_EOL;
    $iReturn = intval($iReturn);
    if($iReturn == 0 && !PostSassProcessor::ProcessAllFiles($oSassDirs, $bPrettyPrint, !$bNoSourceMaps))
      $iReturn = -1;
    return $iReturn === 0;
  }
}