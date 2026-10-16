<?php

// triple store
$config['sparql_endpoint'] = 'http://localhost:3002/u/local/bhl/sparql';
//$config['sparql_endpoint'] = 'https://koetai.bionames.org/u/0000-0002-7101-9767/bhl/sparql';
$config['triplestore'] = 'oxigraph';

// external sites
$config['aws']     = 'https://bhl-open-data.s3.us-east-2.amazonaws.com';
$config['bhl']     = 'https://www.biodiversitylibrary.org';
$config['ia']      = 'https://archive.org/details';

// web site
$config['ld4bhl']  = 'http://localhost/ldf4';

// canvas

// Small WEBP derivatives are 150 pixels wide
$config['thumbnail_width'] = 150;

// SQLite database with scan dimensions for each Internet Archive item
$config['canvas_db'] = dirname(__FILE__) . '/canvas.sqlite';

?>
