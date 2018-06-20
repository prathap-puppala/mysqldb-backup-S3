<?php
require_once( 'vendor/aws-autoloader.php' );
use Aws\Sdk;

// AWS key
define( 'AWS_KEY', 'YOUR AWS ACCOUNT KEY' );

// AWS secret
define( 'AWS_SECRET', 'YOUR AWS ACCOUNT SECRET KEY' );

// AWS bucket to use (bucket is created if it doesn't exist)
define( 'BUCKET', 'BUCKET NAME' );

// backup limit
define( 'BACKUP_LIMIT', '-1 day' ); // eg. -1 day, -2 weeks, -1 month

// file size/upload limit in bytes
define( 'FILE_SIZE_LIMIT', '2048' );

class UploadToAWS
{
	function movefile( $fileName, $filePath ) {
		
		// directory path of backup files
		define( 'DB_DIRECTORY', $filePath . '/' );

		// source file to upload
		define( 'SOURCE_FILE', $fileName );

		$this->check_file_exists( SOURCE_FILE );

		$aws = Aws::factory( array( 'credentials' => array( 'key' => AWS_KEY, 'secret' => AWS_SECRET ) ) );
		$s3 = $aws->get('S3');

		$this->check_bucket_exists( $s3 );

		$status=$this->upload_file( $s3, SOURCE_FILE );

		$files = $this->get_files_in_bucket( $s3 );

		if ( !empty( $files ) ) {
			$this->delete_files( $s3, $files );
		}
   return $status;
	}

	// check if db backup file exists
	private function check_file_exists( $file = null ) {
		if ( !file_exists( DB_DIRECTORY . @$file ) || empty( $file ) ) {
			die( 'ERROR: File not found' );
		}
	    if ( is_dir( $file ) == true ) {
	        die( 'ERROR: Please select a valid file.' );
	    }
		$filesize = filesize( DB_DIRECTORY . $file );
	    if ( @$filesize > FILE_SIZE_LIMIT ) {
	        die( 'ERROR: File too large.' );
	    }
	}

	// check if bucket exists, if not then create one
	private function check_bucket_exists( &$s3 ) {
		$bucketexists = false;

		$buckets = $s3->listBuckets();

		foreach ( $buckets['Buckets'] as $bucket ) {
			if ( $bucket['Name'] == BUCKET ) {
				$bucketexists = true;
			}
		}
		if ( $bucketexists == false ) {
			$s3->createBucket( array( 'Bucket' => BUCKET ) );
		}

		$s3->waitUntil( 'BucketExists', array( 'Bucket' => BUCKET ) );
		if ( $bucketexists == false ) {
			// bucket created action
		}
	}

	// upload file in parts
	private function upload_file( &$s3, $source_file ) {
		$files = $this->get_files_in_bucket( $s3 );
		if ( !empty( $files ) ) {
			foreach ( $files as $file ) {
				if ( $file == $source_file ) {
					// file with same name already exists
					return false;
				}
			}
		}
		$filename = $source_file;
		$filesize = filesize( DB_DIRECTORY . $source_file ) . ' Bytes'; 

		// Create a new multipart upload and get the upload ID.
		$fileupload = $s3->createMultipartUpload( array(
			'Bucket'       => BUCKET,
			'Key'          => $filename,
			'StorageClass' => 'REDUCED_REDUNDANCY',
			)
		);
		$uploadId = $fileupload['UploadId'];

		// Upload the file in parts.
		try {
			$file = fopen( DB_DIRECTORY . $source_file, 'r' );
			$parts = array();
			$partNumber = 1;

			while ( !feof( $file ) ) {
			$fileupload = $s3->uploadPart( array(
				'Bucket' => BUCKET,
				'Key' => $filename,
				'UploadId' => $uploadId,
				'PartNumber' => $partNumber,
				'Body' => fread( $file, 5 * 1024 * 1024 )
				)
			);
			$parts[] = array(
				'PartNumber' => $partNumber++,
				'ETag' => $fileupload['ETag']
				);
			}
			fclose( $file );
		} catch ( S3Exception $e ) {
			$fileupload = $s3->abortMultipartUpload( array(
				'Bucket'   => BUCKET,
				'Key'      => $filename,
				'UploadId' => $uploadId
				)
			);
		}

		// Complete multipart upload.
		$fileupload = $s3->completeMultipartUpload( array(
			'Bucket'   => BUCKET,
			'Key'      => $filename,
			'UploadId' => $uploadId,
			'Parts'    => $parts
			)
		);
	}

	private function get_files_in_bucket( &$s3 ) {
		$files = '';
		$iterator = $s3->getIterator( 'ListObjects', array( 'Bucket' => BUCKET ) );
		foreach ( $iterator as $object ) {
			$files[] = $object['Key'];
		}
		return $files;
	}

	private function delete_files( &$s3, $files ) {
		foreach ( $files as $key=>$file ) {
			$dbtime = substr( $file, 0, ( strripos( $file, '_' ) ) );
			if ( $dbtime < strtotime( BACKUP_LIMIT, time() ) ) {
				unset( $files[$key] );
				if ( sizeof($files) < 2 ) {
					// number of backup files is less than 2, deletion stopped.
					return false;
				}
				$deletefile = $s3->deleteObject( array(
					'Bucket' => BUCKET,
					'Key' => $file
					)
				);
			}
		}
	}
}
?>
