<?php

namespace App\Console\Commands;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Mockery\Exception;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SyncFoldersAndFilesToWasabi extends Command
{
    protected $signature = 'sync:images';
    protected $description = 'Scrape image files, create folders dynamically, and upload to Wasabi';

    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp']; // Allowed image extensions

    public function handle()
    {
        $baseUrl = 'https://unique-media-eg.com/on-msr/wp-content/uploads/2024'; // Replace with your URL
        $this->info("Fetching image files from: $baseUrl");

        // Start the folder processing from the root
        $this->processFolder($baseUrl, 'tenanton-misr/app/uploads/3/5/');

        $this->info('All images have been successfully synced to Wasabi!');
    }

    private function processFolder(string $url, string $currentPath)
    {
        $html = Http::get($url)->body();
        $crawler = new Crawler($html);

        // Extract folder and file links
        $links = $crawler->filter('a')->each(function (Crawler $node) {
            return $node->attr('href');
        });

        foreach ($links as $link) {
            if ($link === '../') {
                continue; // Skip parent directory link
            }

            $fullPath = rtrim($url, '/') . '/' . $link;

            if ($this->isFolder($link)) {

                $this->info("Processing folder: $currentPath$link");

                // Create the folder in Wasabi
                /*Storage::disk('wasabi')
                    ->makeDirectory("$currentPath$link");*/

                // Process subfolders recursively
                $this->processFolder($fullPath, "$currentPath$link/");
            } elseif ($this->isImage($link)) {
                $link = '01-'.$link.'-2024';
                $this->info("current link: $currentPath");
                $this->info("Downloading image: $currentPath$link");
                $this->uploadToWasabi($fullPath, "$currentPath$link");
            }
        }
    }

    private function isFolder(string $link): bool
    {
        return str_ends_with($link, '/'); // Common directory listing convention for folders
    }

    private function isImage(string $link): bool
    {
        $extension = strtolower(pathinfo($link, PATHINFO_EXTENSION));
        return in_array($extension, $this->allowedExtensions);
    }


    private function uploadToWasabi(string $fileUrl, string $path)
    {
        try {
            $path = str_replace('//', '/', $path);

            // Initialize the S3Client with Wasabi configuration
            $s3Client = new S3Client([
                'version' => 'latest',
                'region'  => env('AWS_DEFAULT_REGION'),
                'endpoint' => env('AWS_URL'),
                'credentials' => [
                    'key'    => env('AWS_ACCESS_KEY_ID'),
                    'secret' => env('AWS_SECRET_ACCESS_KEY'),
                ],
            ]);

            // Download the file content as a seekable stream (write to a temporary file)
            $response = Http::withOptions(['stream' => true])->get($fileUrl);
            $fileContent = $response->getBody();

            // Create a temporary file to hold the content and make it seekable
            $tempFile = tmpfile();  // Create a temporary file
            $filePath = stream_get_meta_data($tempFile)['uri']; // Get file URI

            // Write the stream content to the temporary file
            fwrite($tempFile, $fileContent);

            // Rewind the file pointer to the beginning of the file (to ensure it's seekable)
            rewind($tempFile);

            // Calculate the file's content length and MD5 checksum
            $contentLength = filesize($filePath);  // Get the file size
            $md5 = base64_encode(md5_file($filePath, true)); // Calculate MD5 checksum of the file content

            // Upload the file to Wasabi using the seekable temporary file stream
            $result = $s3Client->putObject([
                'Bucket' => env('AWS_BUCKET'),  // Your Wasabi bucket name
                'Key'    => $path,  // The destination path in the Wasabi bucket
                'Body'   => fopen($filePath, 'rb'),  // Open the seekable temp file for upload
                'ACL'    => 'public-read',  // Or 'private' depending on your use case
                'ContentLength' => $contentLength,  // Content length
                'ContentMD5' => $md5,  // MD5 hash for the file content
            ]);

            // Log the success
            $this->info("Uploaded to Wasabi: $path");

            // Clean up the temporary file
            fclose($tempFile);

        } catch (AwsException $e) {
            // Catch errors related to AWS SDK
            $this->error("Failed to upload: $fileUrl. Error: {$e->getMessage()}");
        } catch (\Exception $e) {
            // Catch other errors
            $this->error("Error: {$e->getMessage()}");
        }
    }

}
