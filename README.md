# css-cli

_This package is still in experimental stages_

This is a Command line interface for automating the process of running CSS Development Tools.  It is specifically meant for use with a Sterling Full Stack Framework directory structure, however it could be used to accomodate any directory structure using additional command line arguments. 

The typical development cycle when developing custom CSS with SASS is:

 1. Edit the *.scss files located in one directory
 2. Run a Sass processor to produce the CSS output files (usually output to another directory)
 3. Run a tool against the generated CSS to add or remove vendor prefixes to support the target browsers
 4. Run a tool to clean whitespace and formatting, and either generate expanded CSS (for development) or compressed CSS (for production)

This utility automates these tasks.

At the time of this development, many of the best tools for accomplishing these tasks are written using JavaScript and are often implemented as plug-ins for tool chain utilities such as gulp.  This utility takes advantage of some of those tools by leveraging the multi-threaded capabilities of PHP and shelling out to the various JavaScript tools which run under a separate node process.  Also, in order to take advantage of the most up-to-date and most complete implementation of SASS, this interface makes use of the compiled Dart SASS processor which also runs in a separate process.  Finally, the css-cli implements a shell-like interpreter which can accept commands that change the output characteristics of the CSS generated.

## What is the advantage of this utility over a tool chain like Gulp?

This utility implements a multi-threaded command line interface that can change the output characteristics on the fly.  For example, durring normal development you most likely will want expanded CSS and map generation.  Before moving to a production server, you most likely want to turn off maps and compress the CSS generated.

Typically you would have this utility running in a terminal shell while you perform edits to the SCSS.  Once you get things working the way you want, you can simply go to the terminal where css-cli is running, type a command, and the new production format of all of the files being watched will be generated at once for final testing before release.   

## Installation

This package requires several prerequisits:

 - A thread safe version of PHP >= 8.3.
 - The parallel php extension (which requires the thread safe version of PHP)
 - Node.js, npm and npx.  

See INSTALL.md for more details.

