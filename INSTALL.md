# Install steps

The installation is not straightforward for two reasons: 1) in order to run the multi-threaded command line interface, a Thread Safe version of PHP is required that can support the "parallel" extention, and 2) there are also javascript (Node.js) components called by the utility

The basic installation steps are:

1. Install Node.js, npm
2. Install dart sass
3. Install ZTS version of PHP, >= 8.3
4. Install the Parallel extention for PHP in the ZTS version's modules/ini
5. Use the ZTS version of PHP with composer.phar to create a new project and require sterlingdesign/css-cli
6. Install the nodejs project requirements with npm and update the caniuse database inside the sterlingdesign/css-cli project


## 1. Install Node.js, npm, npx

For all platforms and the latest releases, see https://nodejs.org/en/download

On Ubuntu Linux

<pre>
sudo apt install nodejs -y
sudo apt install npm -y
</pre>

## 2. Install dart sass

You will want the command line version: see https://sass-lang.com/install/

On Ubuntu, it is simply

<pre>npm install -g sass</pre>

Once installed, you should be able to type "sass --version" from any command line or terminal window.

## 3. Install Thread Safe Version of PHP

You will need PHP 8.3 or later.

Care should be taken not to mix the Non-Thread Safe version of PHP with the Thread Safe version.  It is not recommended (at this time as of PHP 8.4) to run the Thread Safe version under the Apache web server.

You can test any php executable in question by executing

<code>% php --version</code>

The normal, Non-Thread Safe version will print (NTS) on the version information, for example

<pre>% php --version
PHP 8.4.12 (cli) (built: Aug 29 2025 06:48:12) (NTS)
</pre>  

The Thread Safe version needed to run the parallel extension will print (ZTS) in the version information, for example

<pre>% phpZTS --version
PHP 8.4.13 (cli) (built: Oct  1 2025 13:27:44) (ZTS)
</pre>

### On Windows

There have generally been pre-built PHP ZTS binaries available for download from various websites.  see the php.net and parallel project page () for some pointers

### On Linux 

You may need to build the ZTS version of PHP from source code.  

There are better references than what follows here, but these are my notes.  It's not that difficult, and will give your CPU a good workout.  A typical set of commands that might get the job done are:

<pre>
    wget http://www.php.net/distributions/php-X.Y.Z.tar.gz
    tar zxvf php-X.Y.Z.tar.gz
    cd php-X.Y.Z
</pre>

Then, on Ubuntu, for example:

<pre>
    sudo apt update
    sudo apt install autoconf automake bison build-essential curl flex libtool libssl-dev libcurl4-openssl-dev libxml2-dev libreadline7 libreadline-dev libsqlite3-dev libzip-dev libzip4 openssl pkg-config re2c sqlite3 zlib1g-dev
</pre>

Then

<pre>
    ./buildconf
    ./configure --enable-zts --prefix=$HOME/.local --program-suffix=ZTS --disable-phpdbg --enable-mbstring --with-pdo-mysql --with-xsl --with-zip --with-openssl --with-curl --enable-exif --enable-ftp
</pre>

In this example, notice the _"--prefix=$HOME/.local"_ configuration option will cause the generated files to NOT be installed globally: We don't want to change the NTS version of PHP normally used and that Apache needs.  Also, the _"--program-suffix=ZTS"_ switch causes all the generated executable scripts and binary files to have a different name.  This is an extra safeguard so the new ZTS version doesn't conflict with the other, more common, NTS version.

Most likely, you will get errors running the configure script.  Install any missing development headers until the 'configure' command completes without errors.  

<pre>
    sudo apt install libzip-dev
    sudo apt install libxslt1-dev
</pre>

At the time of this writing, the *Oniguruma* package required to build PHP with *mbstring* support is not available for download.  You'll probably have to build it from git source code.  Please visit the package website to see what's going on: https://github.com/kkos/oniguruma

Once you install all dependencies and generate configure without error, you should be able to

<pre>
    make -j4
    make test
    make install
    make clean
</pre>

Note the -j4 switch allows make (and the compiler) to use more cores on your computer and will complete the build faster.  See "man make"

By now you should be able to run "phpZTS --version" and verify that it works and it's --version command reports "ZTS", not "NTS".

## 4. Install the Parallel extention

If you downloaded the binaries for Windows, you can simply download a pre-compiled version of parallel. see https://www.php.net/manual/en/book.parallel.php for a link to pre-built binaries.  Also, krakjoe's project page looks like it has a wide selection of downloads, including docker, at https://github.com/krakjoe?tab=packages&repo_name=parallel

The recommended way is to use PECL or PIE tools to obtain them, but make sure you run those tools with your ZTS version of PHP so that the extension gets installed into the local/ZTS extension directory, not the global NTS PHP directory used by Apache.  For example,

<code>phpZTS /path/to/pie.phar install parallel</code>

If you compiled the ZTS version of PHP, most likely you will need to compile the parallel extension from scratch.  for the sourcecode and latest info, see https://github.com/krakjoe/parallel

Once you have a binary parallel extension installed, don't forget to update the php.ini file so that PHP loads the extension.  To locate where it is located, use

<code>
    phpZTS --ini
</code>

Then, in the configuration file, add the line

<pre>extension=parallel</pre>

## 5. Use the ZTS version of PHP with composer.phar to create a new project and require sterlingdesign/css-cli

<pre>
    cd ~
    mkdir SterlingStackTools
    cd SterlingStackTools
    phpZTS /path/to/composer.phar init
    phpZTS /path/to/composer.phar require sterlingdesign/css-cli
</pre>

## 6. Install the Node.js project requirements with npm and update the caniuse database

From a terminal or command window, change into the project directory created and initialized as above, then install the node packages required to run that sub-component

<pre>
    cd vendor/sterlingdesign/css-cli/src/nodejs/cssfixerupper
    npm install
    npx browserslist@latest --update-db
</pre>

To test the node components, at this point you should be able to run the command

<pre>
    node index.js -h
</pre>

and get a Useage message for this sub-component.  It is possible to install this component globally so that it could be run from any command line as described, but it is not nessesary or reccomended at this time.

## 7. Test the css-cli tool

Assuming your Thread Safe version of php is on your path, and you created a directory to install the tool into as above, ~/SterlingStackTools, then you can test basic operation by running

<pre>
phpZTS -f ~/SterlingStackTools/vendor/sterlingdesign/css-cli/src/sass-watcher.php -- --help
</pre>
You should get a usage listing like this:
<pre>
Usage: /home/adam/SterlingStackTools/vendor/sterlingdesign/css-cli/src/sass-watcher.php [options] [operands]

Options:
  -v, --version               Display version number and exit
  -h, --help                  Display this usage information and exit
  -g, --generate-dart         Generate a list of directories compatible with dart-sass cli and exit without any other
                              processing
  -p, --pretty-print          Run the css post sass tool with the pretty-print flag set
  -m, --keepmaps              Instruct dart to generate mapps and the post-dart processor to keep them
  -i, --immediate             Run SASS and PostProcessors immediately on startup.  All detected files will be updated.
  -w, --watch                 Monitor the sass output files and run css tools when modified
  -s, --sterling-stack <arg>  A directory that is the root directory of a sterling stack.  Sass source and output
                              folders will be automatically detected


</pre>

To test functionality, you can use the supplied testing css:

<pre>
    phpZTS -f "$HOME/SterlingStackTools/vendor/sterlingdesign/css-cli/src/sass-watcher.php" -- -wpm "$HOME/SterlingStackTools/vendor/sterlingdesign/css-cli/testscss":"$HOME/SterlingStackTools/vendor/sterlingdesign/css-cli/testoutcss"
</pre>

The above command should produce the files example.css and example.css.map in the output directory. 

While the command interface is running, you can also test the other commands available.  Type help and press enter for a full list.

Note that the standard usage pattern is to call this utility with arguments of "source-sass-file-directory":"output-css-directory".

Note also that a "Stack" directory is a special directory structure specific to the Sterling Stack which consists of:

<pre>Top-Level-Dir/FrameworkCommon/sass:Top-Level-Dir/FrameworkCommon/public/style</pre>

plus one or more domain and host directories which are scanned for at startup:

<pre>
Top-Level-Dir/domain.com/sass:Top-Level-Dir/domain.com/public/style
Top-Level-Dir/domain.com/hosts/host.domain.com/sass:Top-Level-Dir/domain.com/hosts/host.domain.com/public/style
</pre>

In other words, when using the "Stack" option, one or more -s options are followed by only the Top-Level-Dir and the sass source:output directories are searched for and added automatically.  This is a custom directory structure specific to the Sterling Stack.  The Sterling Stack is not published or in wide use at this time.



 
