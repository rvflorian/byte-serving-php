<?php
 // Originally by rvflorian@github.com.
 // Re-factorized and enhanced by DannyNiu/NJF once Jan. 2020.
 /*
  * MIT License
  *
  *  Copyright (c) 2018 Răzvan Valentin Florian
  *
  * Permission is hereby granted, free of charge, to any person obtaining a copy
  * of this software and associated documentation files (the "Software"), to deal
  * in the Software without restriction, including without limitation the rights
  * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  * copies of the Software, and to permit persons to whom the Software is
  * furnished to do so, subject to the following conditions:
  *
  * The above copyright notice and this permission notice shall be included in all
  * copies or substantial portions of the Software.
  *
  * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  * SOFTWARE.
  */

 function byteserve_set_range($range, $filesize, &$first, &$last)
 {
   # Sets the first and last bytes of a range, given a range expressed as a string
   # and the size of the file.
   #
   # If the end of the range is not specified, or the end of the range is greater
   # than the length of the file, $last is set as the end of the file.
   #
   # If the begining of the range is not specified, the meaning of the value after
   # the dash is "get the last n bytes of the file".
   #
   # If $first is greater than $last, the range is not satisfiable, and we should
   # return a response with a status of 416 (Requested range not satisfiable).
   #
   # Examples:
   # $range='0-499', $filesize=1000 => $first=0, $last=499 .
   # $range='500-', $filesize=1000 => $first=500, $last=999 .
   # $range='500-1200', $filesize=1000 => $first=500, $last=999 .
   # $range='-200', $filesize=1000 => $first=800, $last=999 .

   $dash = strpos($range, '-');
   $first = trim(substr($range, 0, $dash));
   $last = trim(substr($range, $dash+1));

   if( $first == '' )
   {
     //suffix byte range: gets last n bytes
     $suffix = $last;
     $last = $filesize-1;
     $first = $filesize-$suffix;
     if( $first < 0 ) $first = 0;
   }
   else if( $last=='' || $last > $filesize-1 ) $last = $filesize-1;

   if( $first > $last )
   {
     //unsatisfiable range
     http_response_code(416);
     header("Status: 416 Requested range not satisfiable");
     header("Content-Range: */$filesize");
     die(); // My (DannyNiu's) coding style is to differenciate success exits from failure dies [diff-exit-die].
   }
 }

 function byteserve_buffered_read($file, $bytes, $buffer_size=1024)
 {
   # Outputs up to $bytes from the file $file to standard output, $buffer_size bytes at a time.
   # ``$file'' may be pre-seeked to a sub-range of a larger (say, uncompressed archive) file.

   $bytes_left=$bytes;
   while( $bytes_left > 0 && !feof($file) )
   {
     $bytes_to_read = min($buffer_size, $bytes_left);
     $bytes_left-=$bytes_to_read;
     $contents=fread($file, $bytes_to_read);
     echo $contents;
     flush();
   }
 }

 function byteserve($filename, $fileoffset=0, $filesize=-1, $mimetype="application/octet-stream")
 {
   // 2019-08-22: added by DannyNiu/NJF.
   // Indicating potentially cacheable results.
   clearstatcache();
   $mt = stat($filename)['mtime'];
   header("Last-Modified: ".gmdate("D, d M Y H:i:s", $mt)." GMT");
   header("Cache-Control: public, max-age=3600");

   if( isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) )
   {
     $rt = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
     if( $rt > $mt ) {
       http_response_code(304);
       return;
     }
   }

   /*
      Byteserves the file $filename.
      When there is a request for a single range, the content is transmitted
      with a Content-Range header, and a Content-Length header showing the number
      of bytes actually transferred.
      When there is a request for multiple ranges, these are transmitted as a
      multipart message. The multipart media type used for this purpose is
      "multipart/byteranges".
    */
   if( $filesize == -1 )
   {
     if( $fileoffset > 0 )
     {
       http_response_code(500);
       die(); // [diff-exit-die]
     }

     $filesize = filesize($filename);
     $mimetype = mime_content_type($filename);
   }

   $file = fopen($filename, "rb");
   fseek($file, $fileoffset, SEEK_SET);

   $ranges=NULL;
   if( $_SERVER['REQUEST_METHOD']=='GET' &&
       isset($_SERVER['HTTP_RANGE']) &&
       $range = stristr(trim($_SERVER['HTTP_RANGE']), 'bytes=') )
   {
     $range = substr($range, 6); // 6 == strlen("bytes=")
     $boundary = bin2hex(random_bytes(48)); // set a random boundary.
     $ranges = explode(',', $range);
   }

   if( $ranges && count($ranges) ){
     http_response_code(206);
     header("Accept-Ranges: bytes");

     if( count($ranges) > 1 )
     {
       ## More than one range is requested. ##

       //compute content length
       $content_length=0;
       foreach ($ranges as $range){
         byteserve_set_range($range, $filesize, $first, $last);
         $content_length+=strlen("\r\n--$boundary\r\n");
         $content_length+=strlen("Content-Type: $mimetype\r\n");
         $content_length+=strlen("Content-Range: bytes $first-$last/$filesize\r\n\r\n");
         $content_length+=$last-$first+1;
       }
       $content_length+=strlen("\r\n--$boundary--\r\n");

       //output headers
       header("Content-Length: $content_length");
       // see http://httpd.apache.org/docs/misc/known_client_problems.html
       // and https://docs.oracle.com/cd/B14098_01/web.1012/q20206/misc/known_client_problems.html
       // for an discussion of x-byteranges vs. byteranges
       header("Content-Type: multipart/x-byteranges; boundary=$boundary");

       //output the content
       foreach ($ranges as $range){
         byteserve_set_range($range, $filesize, $first, $last);
         echo "\r\n--$boundary\r\n";
         echo "Content-Type: $mimetype\r\n";
         echo "Content-Range: bytes $first-$last/$filesize\r\n\r\n";
         fseek($file, $first+$fileoffset);
         byteserve_buffered_read ($file, $last-$first+1);
       }
       echo "\r\n--$boundary--\r\n";
     }
     else
     {
       ## A single range is requested. ##

       $range=$ranges[0];
       byteserve_set_range($range, $filesize, $first, $last);
       header("Content-Length: ".($last-$first+1) );
       header("Content-Range: bytes $first-$last/$filesize");
       header("Content-Type: $mimetype");
       fseek($file, $first+$fileoffset);
       byteserve_buffered_read($file, $last-$first+1);
     }
   }
   else
   {
     ## no byteserving ##
     header("Accept-Ranges: bytes");
     header("Content-Length: $filesize");
     header("Content-Type: $mimetype");
     fseek($file, $fileoffset);
     byteserve_buffered_read($file, $filesize);
   }
   fclose($file);
   return; // let caller do other back-stage processing. 
 }

 //do not send cache limiter header
 # ini_set('session.cache_limiter','none');
