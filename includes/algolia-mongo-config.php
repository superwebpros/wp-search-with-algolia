<?php
/**
 * MongoDB Configuration for Algolia Logging
 * 
 * Add this to your wp-config.php or as a separate configuration file
 */

// MongoDB connection details for Algolia logging
define( 'MONGODB_URI', 'mongodb+srv://wp_algolia_user:WpAlgolia2025!Secure@ch-db-mongodb-nyc1-54962-66672fda.mongo.ondigitalocean.com/wp_algolia_logs?tls=true&authSource=wp_algolia_logs&replicaSet=ch-db-mongodb-nyc1-54962' );
define( 'MONGODB_DATABASE', 'wp_algolia_logs' );

// Optional: Enable MongoDB logging for Algolia
define( 'ALGOLIA_ENABLE_MONGO_LOGGING', true );

// Optional: Set custom buffer size (default is 50)
define( 'ALGOLIA_MONGO_BUFFER_SIZE', 50 );

// Optional: Set TTL for logs in days (default is 7)
define( 'ALGOLIA_MONGO_TTL_DAYS', 7 );