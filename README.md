# Byte serving from PHP

## What is byte serving?

Byte serving (or byteserving) is the ability of a web server to provide a range of bytes in a file instead of the entire file. If a file is being “byte served”, that means that the server which is sending the file is able to give specific bytes that the client (e.g., a web browser) requests. This feature of the HTTP protocol is commonly used by the Adobe Acrobat Reader plugin. For example, if a PDF file is being byte served, Acrobat can ask for the bytes for the 2nd page, and the server will send only the bytes for the 2nd page. In order to benefit of this HTTP protocol feature for viewing PDF files, two conditions must be fulfilled:
- the server must be able to respond to byte ranges requests;
- the PDF file must be "optimized", i.e. have a linear structure that would permit the independent download of separate pages or sections (otherwise Acrobat will probably not attempt byte ranges request).

This may be needed for, e.g.:
- partial file downloading;
- resuming file downloading.

## Why byte serving from PHP?

The byte serving of regular PDF files is usually managed by the web server, if it is set up correspondingly. However, one may sometimes need to generate PDF file dynamically from PHP. For example, we would like to restrict access to certain PDF files to users authenticated by PHP, or to serve PDF files generated on the fly from PHP. If we would like our users to benefit of byte serving (view the first page of the file, or other particular pages without downloading the entire file), we must implement byte serving from inside PHP.

## How to do it?

To implement byteserving, the script should do the following:
- notify the client that it accepts byte ranges requests;
- detect byte ranges requests;
- extract from the file the requested byte range and serve it.

For more details about how byteserving works, you may check the documentation for the HTTP/1.1 protocol.

## Known issues

It seems that when a byte ranges request by the Acrobat Reader plugin is not delivered by the server, Acrobat Reader may give a "There was a problem reading this document (109)" error when the user gets to the part of the PDF file that was not delivered. A typical scenario to get this error is to navigate quickly back and forth inside a PDF file while it downloads (by clicking the side navigation bar, for example). This results in many requests being submitted to the server in a short time, and the response to some of them may not reach the Acrobat Reader, due to network latency. However, this is not a bug of this script, but a bug in Acrobat: the same phenomenon also appears with files served directly by the Apache server.

## History and acknowledgements

Initial code was written by [Răzvan Valentin Florian](https://florian.io/) and was first released online on July 23, 2004. Mathieu Roche and Gaetano Giunta contributed to detecting some bugs in that version of the script, which was last updated May 25, 2005, prior to being migrated to GitHub on September 24, 2018. 

[Danny Niu](https://github.com/dannyniu) is currently maintaining the code and contributing improvements.
