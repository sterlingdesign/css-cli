cssfixerupper
by Seth Adam Manley

This module was developed as part of the php package sterlingdesign/css-cli.  It can also be run as a standalone
utility to perform autoprefixing, cleanup and compression on CSS files.

The autoprefixer module uses the "browserlist" database which needs to be updated periodically:

npx browserslist@latest --update-db

--or update everything, but may cause incompatibilities with module--

npm update
