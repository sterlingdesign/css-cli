<?php
use SevenEcks\Ansi\Colorize;

//-------------------------------------------------------------------------------------------------
function CliError($item)
{
  echo Colorize::red("ERROR: ");
  if(is_string($item))
    writeErrText($item);
  else if(is_array($item))
    writeErrText(print_r($item, true));
  else
    writeErrText(strval($item));
  echo PHP_EOL;
}
//-------------------------------------------------------------------------------------------------
function writeErrText(string $text)
{
  echo Colorize::lightRed($text);
}
//-------------------------------------------------------------------------------------------------
function CliWarning($item)
{
  echo Colorize::yellow("WARNING: ");
  if(is_string($item))
    writeWarningText($item);
  else if(is_array($item))
    writeWarningText(print_r($item, true));
  else
    writeWarningText(strval($item));
  echo PHP_EOL;
}
//-------------------------------------------------------------------------------------------------
function writeWarningText(string $text)
{
  echo Colorize::lightGray($text);
}
//-------------------------------------------------------------------------------------------------
function CliDebug($item)
{
  echo Colorize::purple("DEBUG: ");
  if(is_string($item))
    writeDebugText($item);
  else if(is_array($item))
    writeDebugText(print_r($item, true));
  else
    writeDebugText(strval($item));
  echo PHP_EOL;
}
//-------------------------------------------------------------------------------------------------
function writeDebugText(string $text)
{
  echo Colorize::darkGray($text);
}
//-------------------------------------------------------------------------------------------------
function CliInfo(string $text)
{
  writeInfoText($text);
  echo PHP_EOL;
}
//-------------------------------------------------------------------------------------------------
function writeInfoText(string $text)
{
  echo Colorize::green($text);
}
//-------------------------------------------------------------------------------------------------
function resolveConfigFile($basename) : ?string
{
  if(!is_string($basename) || strlen($basename) == 0)
    return null;
  $basename = str_replace('\\', '/', $basename);
  $fullpath = $basename;
  if(IsWindowsOS())
    {
    if(strlen($fullpath) <= 2 || ':' !== $fullpath[1])
      {
      $fullpath = getcwd();
      if(strlen($fullpath) == 0)
        $fullpath = "C:/";
      if($fullpath[-1] !== '/')
        $fullpath .= '/';
      if($basename[0] === '/')
        $fullpath = $fullpath[0] . ':';
      $fullpath .= $basename;
      }
    }
  else // not windows
    {
    if($fullpath[0] !== '/')
      {
      $fullpath = getcwd();
      $fullpath = str_replace('\\', '/', $fullpath);
      if(strlen($fullpath) == 0)
        $fullpath = '/';
      if($fullpath[-1] !== '/')
        $fullpath .= '/';
      $fullpath .= $basename;
      }
    }

  if(file_exists($fullpath))
    {
    $fullpath = realpath($fullpath);
    if(false === $fullpath || empty($fullpath) || is_dir($fullpath))
      $fullpath = null;
    }
  else
    $fullpath = null;

  return $fullpath;
}
//-------------------------------------------------------------------------------------------------
function sdMakeFilePath(string $Path, string $strFile) : string
{
  $Path = str_replace("\\", "/", $Path);
  $strFile = str_replace("\\", "/", $strFile);
  if(strlen($Path) && $Path[-1] !== "/")
    $Path .= "/";
  if(strlen($strFile) && $strFile[0]  === "/")
    $strFile = substr($strFile, 1);
  $FullPath = $Path . $strFile;
  if(IsWindowsOS())
    $FullPath = str_replace('/', '\\',$FullPath);
  return $FullPath;
}
//-------------------------------------------------------------------------------------------------
function IsWindowsOS() : bool
{
  if(defined('PHP_OS_FAMILY'))
    return (PHP_OS_FAMILY === 'Windows');
  else
    return (strcasecmp(substr(PHP_OS, 0, 3), "WIN") === 0);
}

//-------------------------------------------------------------------------------------------------
function GetCliUserInputLine(string $strMsg, string $strDefault) : string
{
  writeInfoText($strMsg);
  if(strlen($strDefault))
    writeInfoText(" [{$strDefault}] ");
  $handle = fopen ("php://stdin","r");
  $line = fgets($handle);
  fclose($handle);
  //echo strlen($line) . " characters input";
  $line = trim($line);
  if(strlen($line) == 0)
    $line = $strDefault;
  return $line;
}
//-------------------------------------------------------------------------------------------------
function sdRandomString($length, $SetOfChars = null)
{
  $include_chars = $SetOfChars;
  if(empty($include_chars))
    $include_chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
  // Uncomment below to include symbols
  // $include_chars .= "[{(!@#$%^/&*_+;?\:)}]";
  $charLength = strlen($include_chars);
  $randomString = '';
  for ($i = 0; $i < $length; $i++) {
  $randomString .= $include_chars[rand(0, $charLength - 1)];
  }
  return $randomString;
}
//-------------------------------------------------------------------------------------------------
function sdProcessClose($process, array &$pipes, bool $bQuite = false, float $fWaitSeconds = 0.25) : int
{
  try
    {
    // normally, a process should quit when the pipes are closed
    sdCloseProcessPipes($pipes, $bQuite);
    // after closing pipes, wait for normal termination up to $fWaitSeconds
    $arStatus = sdWaitOnProcessStop($process, $fWaitSeconds);
    $pid = is_numeric($arStatus['pid']) ? $arStatus['pid'] : null;

    // A suggested work-around, posted by "v dot denegin at yahoo dot com"
    // on the php.net manual page for proc_terminate()
    if($arStatus['running'] === true)
      {
      if(!$bQuite)
        CliError("Process {$pid} failed to stop normally.");
      $iResult = sdKillProcess($process, $bQuite);
      }
    else
      $iResult = proc_close($process);
    }
  catch(\Throwable $throwable)
    {
    if(!$bQuite)
      CliError($throwable->getMessage());
    $iResult = -1;
    }
  return $iResult;
}
//-------------------------------------------------------------------------------------------------
function sdWaitOnProcessStop($process, float $fWaitSeconds, bool $bQuite = false) : array
{
  $iTicks = 0;
  $iSleepTime = 25000;
  $MICRO_PER_SECOND = 1000000.0;
  $WAIT_MAX = 20.0;
  $WAIT_MIN = 0.0;
  if($fWaitSeconds > $WAIT_MAX)
    $fWaitSeconds = $WAIT_MAX;
  else if($fWaitSeconds < $WAIT_MIN)
    $fWaitSeconds = $WAIT_MIN;
  try
    {
    $arStatus = proc_get_status($process);
    while(floatval($iTicks++ * $iSleepTime) < ($fWaitSeconds * $MICRO_PER_SECOND) && $arStatus['running'])
      {
      usleep($iSleepTime);
      $arStatus = proc_get_status($process);
      }
    }
  catch(\Throwable $throwable)
    {
    $arStatus = [];
    if(!$bQuite)
      CliError($throwable->getMessage());
    }

  return $arStatus;
}
//-------------------------------------------------------------------------------------------------
function sdKillProcess($process, array &$pipes, bool $bQuite = false) : int
{
  $iResult = -1;
  try
    {
    $arStatus = proc_get_status($process);
    $pid = is_numeric($arStatus['pid']) ? $arStatus['pid'] : null;
    sdCloseProcessPipes($pipes, $bQuite);

    // A suggested work-around, posted by "v dot denegin at yahoo dot com"
    // on the php.net manual page for proc_terminate()
    if(is_numeric($pid))
      {
      if(!$bQuite)
        CliWarning("Killing process pid = {$pid}...");
      if(IsWindowsOS())
        $strResult = exec("taskkill /F /T /PID $pid");
      else
        $strResult = exec("kill -9 $pid");
      $strResult = trim($strResult);
      if(strlen($strResult) && !$bQuite)
        CliWarning($strResult);
      }
    else if(!$bQuite)
      CliError("Unable to kill process with no PID");

    $arStatus = proc_get_status($process);
    if(is_array($arStatus) && $arStatus['running'] === false)
      $iResult = proc_close($process);
    }
  catch(\Throwable $throwable)
    {
    if(!$bQuite)
      CliError($throwable->getMessage());
    $iResult = -1;
    }
  return $iResult;

}
//-------------------------------------------------------------------------------------------------
function sdCloseProcessPipes(array &$pipes, bool $bQuite = false)
{
  try
    {
    $i = 0;
    foreach($pipes as $pipe)
      {
      if(is_resource($pipe))
        {
        if(fclose($pipe))
          {
          $pipes[$i] = null;
          }
        else if(!$bQuite)
          CliWarning("Failed to close process pipe " . $i);
        }
      $i++;
      }
    }
  catch(Throwable $throwable)
    {
    if(!$bQuite)
      CliError($throwable->getMessage());
    }

}