<?php

namespace Coverslide\AudiobookCaster\Application;

use Silex\Application;
use Silex\Provider\ServiceControllerServiceProvider;
use Silex\Provider\SerializerServiceProvider;
use Silex\Provider\UrlGeneratorServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ParameterBag;

class MainApplication extends Application
{
    /**
     * @var string
     */
    private $rootPath;

    /**
     * @var ParameterBag
     */
    private $config;


    /**
     * @param string $rootPath
     */
    public function __construct($rootPath = '')
    {
        parent::__construct();

        $this->setRootPath($rootPath);
        $this->configure();
        $this->registerProviders();
        //$this->registerControllers();
        $this->defineRoutes();
    }

    /**
     * @param string $path
     */
    public function setRootPath ($path)
    {
        $this->rootPath = $path;
    }

    protected function configure()
    {
        $this->config = new ParameterBag(parse_ini_file($this->rootPath . '/config/config.ini'));

        $this['debug'] = $this->config->get('debug', false);
    }

    protected function registerProviders()
    {
        //$this->register(new ServiceControllerServiceProvider());
        $this->register(new SerializerServiceProvider());
        $this->register(new UrlGeneratorServiceProvider());

        $this->register(new \Silex\Provider\TwigServiceProvider(), array(
            'twig.path' => $this->rootPath . '/views',
        ));

        $this["twig"] = $this->share($this->extend("twig", function (\Twig_Environment $twig, Application $app) {
            $twig->addExtension(new \Coverslide\AudiobookCaster\Utility\HumanFilesizeExtension());

            return $twig;
        }));
    }

    protected function defineRoutes()
    {
        $this->before(array($this, "setTrustedProxies"));
 
        $this->get('/', array($this, "authorsAction"))
            ->bind('index');
        $this->get('/authors.{_format}', array($this, "authorsAction"))
            ->value('_format','html')
            ->bind('authors');
        $this->get('/books.{_format}', array($this, "booksAction"))
            ->value('_format','html')
            ->bind('books');
        $this->get('/files.{_format}', array($this, "filesAction"))
            ->value('_format','html')
            ->bind('files');
        $this->get('/feed.{_format}', array($this, "feedAction"))
            ->value('_format','html')
            ->bind('feed');
        $this->get('/audio', array($this, "audioAction"))->bind('audio');
    }

    public function authorsAction (Request $request)
    {

        $authors = $this->getAuthors();
        $format = $request->getRequestFormat();

        switch ($format) {
            case 'xml':
                $xml = new \SimpleXMLElement('<authors/>');
                foreach ($authors as $author) {
                    $xml->addChild('author', $author);
                }
                return new Response($xml->asXML());
            case 'json':
                return $this->json($authors);
        }

        return $this['twig']->render('authors.html.twig', array('authors' => $authors));
    }

    public function booksAction (Request $request)
    {
        $author = $request->get('author');
        $books = $this->getBooks($author);

        $format = $request->getRequestFormat();

        switch ($format) {
            case 'xml':
                $xml = new \SimpleXMLElement('<books/>');
                foreach ($books as $book) {
                    $xml->addChild('book', $book);
                }
                return new Response($xml->asXML());
            case 'json':
                return $this->json($books);
        }

        return $this['twig']->render('books.html.twig', array('author' => $author, 'books' => $books));
    }

    public function filesAction (Request $request)
    {
        $directoryRoot = rtrim($this->config->get('dir.root'), DIRECTORY_SEPARATOR);
        $author = $request->get('author');
        $book = $request->get('book');
        $directory = join(DIRECTORY_SEPARATOR, array($directoryRoot, $author, $book));
        $files = $this->getAudioFiles($author, $book);

        $fileData = array_map(
            function ($file) use ($directory) {
                return array(
                    'filename' => $file,
                    'filesize' => filesize(join(DIRECTORY_SEPARATOR, array($directory, $file)))
                );
            },
            $files
        );

        if ($request->getRequestFormat() === 'json') {
            return $this->json($fileData);
        }

        return $this['twig']->render(
            'files.html.twig',
            array(
                'author' => $author,
                'book' => $book,
                'files' => $fileData
            )
        );
    }

    public function feedAction (Request $request)
    {
        $protocol = $this->getProtocol($request);
        $startDateConfig = $this->config->get('date.start', '2015-01-01 00:00:00');
        $startDate = new \DateTime($startDateConfig);
        $directoryRoot = rtrim($this->config->get('dir.root'), DIRECTORY_SEPARATOR);
        $author = $request->get('author');
        $book = $request->get('book');
        $files = $this->getAudioFiles($author, $book);

        $itunesNamespace = 'http://www.itunes.com/dtds/podcast-1.0.dtd';

        $rss = new \SimpleXMLElement('<rss xmlns:itunes="' . $itunesNamespace . '"/>');
        $rss->addAttribute('version', '2.0');

        $channel = $rss->addChild('channel');
        $channel->addChild('title', $this->filterXmlChars($author . ' - ' . $book));

        $ordinal = 0;
        $singleDay = new \DateInterval('P1D');

        foreach ($files as $file) {
            $item = $channel->addChild('item');
            $item->addChild('title', $this->filterXmlChars($file));
            $item->addChild('author', $this->filterXmlChars($author));
            $item->addChild('description', $this->filterXmlChars($author . ' - ' . $book . ' - ' . $file));


            $audioUrl = $protocol . '://' . $request->headers->get('host') . $this['url_generator']->generate('audio', 
                array(
                    'author' => $author,
                    'book'   => $book,
                    'file'   => $file
                )
            );
            $item->addChild('guid',$this->filterXmlChars($audioUrl));
            $enclosure = $item->addChild('enclosure');
            $enclosure->addAttribute('url', $audioUrl);

            $fullPath = join(DIRECTORY_SEPARATOR, array($directoryRoot, $author, $book, $file));
            $enclosure->addAttribute('length',filesize($fullPath));
            $enclosure->addAttribute('type',$this->getMimeType($fullPath));
            $item->addChild('pubDate', $startDate->format("D, j M Y H:i:s"));
            //$item->addChild('itunes:duration', $this->getDuration($fullPath), $itunesNamespace);
            $ordinal += 1;
            $startDate->sub($singleDay);
        }

        return new StreamedResponse(
            function () use ($rss) {
                echo $rss->asXML();
            },
            200,
            array(
                'Content-Type' => 'text/xml'
            )
        );
    }

    public function audioAction (Request $request)
    {
        $author = $request->get('author');
        $book = $request->get('book');
        $file = $request->get('file');

        $directoryRoot = rtrim($this->config->get('dir.root'), DIRECTORY_SEPARATOR);

        $directory = join(DIRECTORY_SEPARATOR, array($directoryRoot, $author, $book));

        if (is_dir($directory)) {
            $fullPath = join(DIRECTORY_SEPARATOR, array($directory, $file));
        } else if ($this->isAudioFile($directory)) {
            $fullPath = $directory;
        }

        $fileMimeType = $this->getMimeType($fullPath);

        $response = new StreamedResponse();

        $response->headers->set('Content-Type', $fileMimeType);
        $response->headers->set('Content-Length', filesize($fullPath));

        $response->setCallback(
            function () use ($fullPath) {
                readfile($fullPath);
            }
        );

        return $response;
    }

    public function setTrustedProxies (Request $request)
    {
        $trustedProxiesValue = $this->config->get('proxies');
        
        if ($trustedProxiesValue) {
            $trustedProxies = explode(',', $trustedProxiesValue);
            $request->setTrustedProxies($trustedProxies);
        }
    }

    private function filterXmlChars ($str)
    {
        return htmlspecialchars($str, ENT_COMPAT);
    }

    private function getProtocol (Request $request)
    {
        return $request->isSecure() ? 'https' : 'http';
    }

    private function getMimeType ($fullPath)
    {
        $finfo = new \finfo();
        $fileMimeType = $finfo->file($fullPath, FILEINFO_MIME_TYPE);

        if ($fileMimeType == 'application/octet-stream' && preg_match('/.mp3$/', $fullPath)) {
            $fileMimeType = 'audio/mp3';
        }

        return $fileMimeType;
    }

    private function getAuthors ()
    {
        $directoryRoot = rtrim($this->config->get('dir.root'), DIRECTORY_SEPARATOR);

        $authorNames = scandir($directoryRoot);

        sort($authorNames);

        return $this->filterDirectories($authorNames, $directoryRoot);
    }

    private function getBooks ($author)
    {
        $directoryRoot = rtrim($this->config->get('dir.root'), DIRECTORY_SEPARATOR);
        $authorDirectory = $directoryRoot . DIRECTORY_SEPARATOR . $author;
        $bookNames = scandir($authorDirectory);

        sort($bookNames);

        return $this->filterBookFiles($bookNames, $authorDirectory);
    }

    private function getAudioFiles ($author, $book)
    {
        if ($this->isAudioFile($book)) {
            return array($book);
        } 

        $directoryRoot = rtrim($this->config->get('dir.root'), DIRECTORY_SEPARATOR);
        $bookPath = $directoryRoot . DIRECTORY_SEPARATOR . $author . DIRECTORY_SEPARATOR . $book;

        if (is_dir($bookPath)) {
            $files = $this->walkAudioFiles($bookPath);
            $files = $this->filterDirectoryPrefix($files, $bookPath);
            sort($files);
            return $files;
        } else {
            throw new \Exception("Unknown file type");
        }
    }

    private function getDuration ($path)
    {
        //http://stackoverflow.com/a/7135484
        $time = exec("ffmpeg -i " . escapeshellarg($path) . " 2>&1 | grep 'Duration' | cut -d ' ' -f 4 | sed s/,//");
        list($hms, $milli) = explode('.', $time);
        //list($hours, $minutes, $seconds) = explode(':', $hms);
        //$total_seconds = ($hours * 3600) + ($minutes * 60) + $seconds;
        return $hms;
    }

    private function walkAudioFiles ($path)
    {
        $files = array();
        foreach ($this->filterBookFiles(scandir($path), $path) as $filename) {
            $fullPath = $path . DIRECTORY_SEPARATOR . $filename;
            if (is_dir($fullPath)) {
                $files = array_merge($files, $this->walkAudioFiles($fullPath));
            } else if ($this->isAudioFile($fullPath)) {
                $files[] = $fullPath;
            }
        }
        return $files;
    }

    private function filterDirectories (array $filenames, $directoryRoot)
    {
        $readableFolders = array_filter(
            $filenames,
            function ($filename) use ($directoryRoot) {
                return strpos($filename, '.') !== 0 && is_dir($directoryRoot . DIRECTORY_SEPARATOR . $filename);
            }
        );

        return array_values($readableFolders);
    }

    private function filterBookFiles (array $fileNames, $directoryRoot)
    {
        $readableFiles = array_filter(
            $fileNames,
            \Closure::bind(
                function ($filename) use ($directoryRoot) {
                    return strpos($filename, '.') !== 0 &&
                        (
                            is_dir($directoryRoot . DIRECTORY_SEPARATOR . $filename) ||
                            $this->isAudioFile($filename)
                        );
                },
                $this
            )
        );

        return array_values($readableFiles);
    }

    private function filterDirectoryPrefix (array $files, $directoryPrefix)
    {
        $directoryPrefixRegex = '/^' . preg_quote($directoryPrefix . DIRECTORY_SEPARATOR, '/') . '/';
        return array_map(
            function ($file) use ($directoryPrefixRegex) {
                return preg_replace($directoryPrefixRegex, '', $file);
            },
            $files
        );
    }

    private function getAudioExtensions ()
    {
        $extensionsValue = $this->config->get('audio.extensions', 'mp3,mp4,m4a,m4b');
        return explode(',', $extensionsValue);
    }

    private function getExtensionsRegex ()
    {
        $extensions = $this->getAudioExtensions();
        return '/.(' . join('|', $extensions) . ')$/';
    }

    private function isAudioFile ($path)
    {
        $extensionsRegex = $this->getExtensionsRegex();
        return preg_match($extensionsRegex, $path);
    }
}
