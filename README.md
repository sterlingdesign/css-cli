# sterlingdesign/css-cli

This is a Command line interface (CLI) for automating the process of creating CSS from Sass (SCSS) source files.  It is specifically meant for use with a Sterling Full Stack Framework directory structure, however it could be used to accomodate any directory structure using additional command line arguments.  The command line arguments specify the SCSS source directory to monitor and the output directory where generated css and map files are generated (just like arguments to sass - sourceDir:targetDir).

This CLI combines the capabilites of sass (https://sass-lang.com), the "autoprefixer" library (https://www.npmjs.com/package/autoprefixer), and the "clean-css" library (https://www.npmjs.com/package/clean-css).  It furthermore provides a command shell-like interface that can be used to monitor activity and control output in real time.

### Background

The typical development cycle when developing custom CSS with SASS is:

 1. Edit the *.scss files located in one directory
 2. Run a Sass processor to produce the CSS output files (usually output to another directory)
 3. Run a tool against the generated CSS to add or remove vendor prefixes to support the target browsers
 4. Run a tool to clean whitespace and formatting, and either generate expanded CSS (for development) or compressed CSS (for production)

This utility automates these tasks.

At the time of this development, many of the best tools for accomplishing these tasks are written using JavaScript and are often implemented as plug-ins for tool chain utilities such as gulp.  This utility takes advantage of some of those tools by leveraging the multi-threaded capabilities of PHP and shelling out to the various JavaScript tools which run under a separate node process.  Also, in order to take advantage of the most up-to-date and most complete implementation of SASS, this interface makes use of the compiled Dart SASS processor which also runs in a separate process.  Finally, the css-cli implements a shell-like interpreter which can accept commands that change the output characteristics of the CSS generated.

### What is the advantage of this utility over a tool chain like Gulp?

This utility implements a multi-threaded command line interface that can change the output characteristics on the fly.  For example, durring normal development you most likely will want expanded CSS and map generation.  Before moving to a production server, you most likely want to turn off maps and compress the CSS generated.

Typically you would have this utility running in a terminal shell while you perform edits to the SCSS.  Once you get things working the way you want, you can simply go to the terminal where css-cli is running, type a command, and the new production format of all of the files being watched will be generated at once for final testing before release.   

### Installation

This package requires several prerequisits:

 - A thread safe version of PHP >= 8.3.
 - The parallel php extension (which requires the thread safe version of PHP)
 - Node.js, plus npm and npx  

See INSTALL.md for more details.

### Future

Although CSS standards are heading towards not needing prefixes to support older browsers, there is still a need for them.  Plus, having the capability to change between pretty-printed sass output with map files and compressed output for production is nice to have.  Perhaps someday the sass processor itself will allow command input while running and this tool will become obsolete.

Maintaining this CLI wrapper to keep up with changes in the underlying components is the first priority.  If there is any interest, some options for future development for this package could include generating minified JavaScript and more command options.  
