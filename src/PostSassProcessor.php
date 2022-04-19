<?php
declare(strict_types=1);
namespace Sterling\StackTools;

use parallel\{Channel, Events, Events\Event\Type, Future, Runtime};
use SevenEcks\Ansi\Colorize;

class PostSassProcessor extends SimpleParallelThread
{
  // Older versions of Windows had a 8191 character limit for command line strings.
  //  Here, a few characters were subtracted from that to account for the shell command and
  //  options.  It's unlikely this MAX_ARG limit would be reached under normal circumstances,
  //  but we might as well be prepared in case we are running on a version of windows that has
  //  the old limit.  See PostSassProcessor::ProcessAllFiles()
  const MAX_ARG = 8100;

  /** @var null|\Sterling\StackTools\SassDirectories */
  protected $m_oSassDirs = null;
  protected $m_bPrettyPrint = false;
  protected $m_bUseMaps = false;

//-------------------------------------------------------------------------------------------------
protected function getRuntimeBootstrap(): ?string
{
  return BOOTSTRAP_AUTOLOAD_PHP;
}
//-------------------------------------------------------------------------------------------------
protected function getEntryClosure(): \Closure
{
  return \Closure::fromCallable('static::_monitor');
}
//-------------------------------------------------------------------------------------------------
  public function GetFutureName(): string
  {
    return "PostSassProcessor";
  }
//-------------------------------------------------------------------------------------------------
public function __construct(SassDirectories $oSassDirs, bool $bPrettyPrint, bool $bUseMaps)
{
  $this->m_oSassDirs = $oSassDirs;
  $this->m_bPrettyPrint = $bPrettyPrint;
  $this->m_bUseMaps = $bUseMaps;
}
//-------------------------------------------------------------------------------------------------
public function GetDartSassDiretoryList() : string
{
  return $this->m_oSassDirs->GetDartSassDiretoryList();
}
//-------------------------------------------------------------------------------------------------
public function ProcessAllFiles() : bool
{
  $bOk = true;
  $arAllExpectedOutput = $this->m_oSassDirs->getAllExpectedOutputFiles();
  // for each expected output file, if it exists, run the css-tool on that file
  CliInfo("Processing all " . count($arAllExpectedOutput) . " sass generated files...");
  $filelist = "";
  foreach($arAllExpectedOutput as $css)
    {
    if(file_exists($css))
      {
      if(strlen($filelist) + strlen($css) >= self::MAX_ARG)
        {
        $bOk = $this->CallCssTool($filelist, $this->m_bPrettyPrint, $this->m_bUseMaps);
        $filelist = "";
        if(!$bOk)
          break;
        }
      $filelist .= " \"$css\"";
      }
    else
      CliWarning("Expected Output file does not exist: \"{$css}\"");
    }
  if($bOk && strlen($filelist))
    $bOk = $this->CallCssTool($filelist, $this->m_bPrettyPrint, $this->m_bUseMaps);
  return $bOk;
}
//-------------------------------------------------------------------------------------------------
// This could be done with exec(...), however in the future, we may want to
// run this as a separate thread, so it is more responsive.
// We'll keep the full proc_open() logic for now.
// ALSO, instead of proc_close or proc_terminate, we are using the user function
// sdProcessClose to make sure the process is shut down with no ghost process left running
// sdProcessClose is more reliable than the exec() termination (it seems, as of php 7.3.33)
//
static public function CallCssTool(string $filelist, bool $bPrettyPrint, bool $bUseMaps, bool $bEchoStdOut = true, bool $bEchoStdErr = true) : bool
{
  $bResult = false;
  $process = null;

  $opt = "";
  if($bPrettyPrint)
    $opt .= "p";
  if($bUseMaps)
    $opt .= "m";
  if(strlen($opt))
    $opt = "-" . $opt;

  CliInfo("List of Files to process: " . $filelist);

  try
    {
    $cmd = "node " . __DIR__ . "/nodejs/cssfixerupper/index.js {$opt} {$filelist}";
    $pipes = [];
    $process = proc_open($cmd, array(
      0 => array('pipe', 'r'), // STDIN
      1 => array('pipe', 'w'), // STDOUT
      2 => array('pipe', 'w')  // STDERR
    ), $pipes);
    }
  catch(\Throwable $throwable)
    {
    CliError($throwable->getMessage());
    return false;
    }

  if(is_resource($process))
    {
    if(fclose($pipes[0]))
      $pipes[0] = NULL;
    else
      CliWarning("Failed to close process pipe STDIN");
    // node javascript can take a few seconds to warm up
    $arStatus = sdWaitOnProcessStop($process, 10.0);
    if($bEchoStdOut)
      {
      $strOut = trim(stream_get_contents($pipes[1]));
      if(strlen($strOut))
        echo $strOut . PHP_EOL;
      }
    if($bEchoStdErr)
      {
      $stdErr = trim(stream_get_contents($pipes[2]));
      if(strlen($stdErr) && $bEchoStdErr)
        echo Colorize::lightRed("ERROR OUTPUT: ") . PHP_EOL . $stdErr . PHP_EOL;
      }
    $returnCode = sdProcessClose($process, $pipes, false, 0);
    $bResult = ($returnCode === 0);
    }
  else
    CliError("Failed to create process");

  return $bResult;
}
//-------------------------------------------------------------------------------------------------
public function Start(array $arIGNORED = array()) : Future
{
  $arArgs = [$this->m_oSassDirs->GetAllDirectoryPairs(), $this->m_bPrettyPrint, $this->m_bUseMaps];
  return parent::Start($arArgs);
}
//-------------------------------------------------------------------------------------------------
private static function _monitor(Channel  $channel, array $arSassDirectoryPairs, bool $bPrettyPrint, bool $bUseMaps)
{
  CliInfo("Post Sass Process Monitor is starting...");
  $bContinue = true;
  $event = null;
  $oSassDirectories = null;
  $events = null;
  try
    {
    $oSassDirectories = new SassDirectories($arSassDirectoryPairs);
    $oSassDirectories->InitializeOutputStats();
    $events = new Events();
    $events->addChannel($channel);
    $events->setBlocking(false); // don't block on Events::poll()
    }
  catch(\Throwable $throwable)
    {
    CliError($throwable->getMessage());
    return -1;
    }

  try
    {
    while($bContinue)
      {
      usleep(250000);
      // test to see if we should shut down
      if($event = $events->poll())
        break;

      $arList = $oSassDirectories->GetModifiedFiles();
      $arToProcess = [];
      foreach($arList as $arFileModInfo)
        {
        if($arFileModInfo['modification'] == 'created' || $arFileModInfo['modification'] == 'changed')
          {
          if(file_exists($arFileModInfo['full-path']))
            array_push($arToProcess, $arFileModInfo['full-path']);
          }
        }
      // test to see if we should shut down
      if($event = $events->poll())
        break;
      else if(count($arToProcess) > 0)
        {
        $bContinue = PostSassProcessor::CallCssTool("\"" . implode("\" \"", $arToProcess) . "\"", $bPrettyPrint, $bUseMaps);
        // test again to see if we should shut down
        if($bContinue && ($event = $events->poll()))
          break;
        else if($bContinue)
          $oSassDirectories->UpdateTimestamps($arToProcess);
        }
      }
    }
  catch(\Throwable $throwable)
    {
    CliError($throwable->getMessage());
    }

  if(!$bContinue)
    CliError("Post Sass Process encountered an unrecoverable error and is ending");
  else
    CliInfo("Post Sass Process Monitor is ending.");

  return 0;
}
}