<?php
namespace Shipper;

use Exception;
use Guzzle\Http\Client;

class Shipper
{
    protected $config;
    protected $proc;
    protected $regex;
    protected $map = array();
    protected $destination = array();
    protected $domain;
    protected $files = array();
    protected $date;
    protected $time;
    protected $rollTime;
    protected $http = array();

    public function __construct($config)
    {
        $this->config = $config;
        $this->installSigHandler();
    }

    public function ship($command)
    {
        $this->buildDomainRegex();

        $method = "open$command";
        $this->$method();

        while($line = fgets($this->proc))
        {
            pcntl_signal_dispatch();
            if ($this->shouldProcessLine($line))
            {
                $this->sendToDest($line);
            }
        }

        $this->cleanup();
    }

    protected function openVarnishncsa()
    {
        $cmd = $this->config['commands']['varnishncsa'];
        $this->proc = popen($cmd, "r");

        if (!$this->proc)
        {
            throw new Exception("Failed to run $cmd");
        }
    }

    protected function buildDomainRegex()
    {
        $i = 4;
        $parts = array();
        foreach($this->config['domains'] as $domain => $dest)
        {
            $this->map[$i++] = $dest;
            $parts[] = '('.str_replace('\*', '.*?',preg_quote($domain,'@')).')';
        }

        $this->regex = '@^[^[]+\[([^]]+)\] "(GET|POST) https?://('.implode('|',$parts).')/.*$@i';
    }

    protected function shouldProcessLine($line)
    {
        if (preg_match($this->regex, $line, $match))
        {
            $this->time = strtotime($match[1]);
            $this->date = date('Y-m-d', $this->time);
            $this->rollTime = date($this->config['rotate'], $this->time);
            foreach($match as $i => $m)
            {
                if ($i > 3 && !empty($m))
                {
                    $this->destination = $this->map[$i];
                    break;
                }
            }
            if (isset($this->destination['name']))
		    $this->domain = $this->destination['name'];
	    else
		    $this->domain = $match[3];

            return true;
        }

        return false;
    }

    protected function sendToDest($line)
    {
        // check if we have an open file
        if (!isset($this->files[$this->domain]))
        {
	    echo "Found line for $this->domain\n";
            $this->files[$this->domain] = new \stdClass;
            $this->files[$this->domain]->handle = gzopen($this->filename($this->domain), 'w');
            $this->files[$this->domain]->size = 0;
            $this->files[$this->domain]->time = $this->rollTime;
        }
        elseif($this->rollTime !== $this->files[$this->domain]->time)
	{
	    echo "Time based log roll $this->domain\n";
            $this->rollLog();
            return $this->sendToDest($line);
        }
 	elseif($this->files[$this->domain]->size >= $this->config['maxsize'])
        {
	    echo "Size based log roll $this->domain\n";
            $this->rollLog();
            return $this->sendToDest($line);
        }

        $wrote = fwrite($this->files[$this->domain]->handle, $line, strlen($line));
        $stat = fstat($this->files[$this->domain]->handle);
        $this->files[$this->domain]->size = $stat['size'];

    }

    protected function filename($domain)
    {
        return $this->config['tmpdir']."/$domain.log.gz";
    }

    protected function rollLog()
    {
        fclose($this->files[$this->domain]->handle);
        $this->upload();
        unset($this->files[$this->domain]);
    }

    protected function upload()
    {
        $conf = $this->config['destinations'][$this->destination['destination']];
	$keys = array('{domain}','{date}','{time}'); 
	$values = array($this->domain, $this->date, $this->time);
	foreach($this->destination as $k => $v)
	{
		$keys[] = '{'.$k.'}';
		$values[] = $v;
	}
	
        $path = str_replace($keys, $values, $conf['path']);

	echo "Uploading $path\n";
        $request = $this->http()->put($path);
        $request->setBody(fopen($this->filename($this->domain), 'r'));
        $request->addHeader('Authorization', $conf['auth']);

        $ok = true;
        try
        {
            $response = $request->send();
        }
        catch(Exception $e)
        {
            echo "Something went wrong upload to $path\n";
            echo get_class($e)."\n";
            echo $e->getMessage()."\n";
            //echo $e->getTraceAsString();
            $ok = false;
        }

        if (!$ok)
        {
            $backup_dir = $this->config['tmpdir'].'/backups';
            if (!file_exists($backup_dir))
                mkdir($backup_dir);

            $backup_file = $backup_dir.'/'.$path;
            $backup_dir = dirname($backup_file);
            if (!file_exists($backup_dir))
                mkdir($backup_dir, 0755, true);

            echo "Upload failed backing up log: $backup_file\n";
            rename($this->filename($this->domain), $backup_file);
        }
	else
	{
	    echo "Upload complete\n";
	}
    }

    protected function installSigHandler()
    {
        pcntl_signal(SIGTERM, array($this, "signalHandler"));
        pcntl_signal(SIGINT, array($this, "signalHandler"));
    }

    protected function cleanup()
    {
	echo "\nRuning cleanup\n";
        foreach($this->files as $domain => $o)
        {
            $this->domain = $domain;
            $this->rollLog();
        }
    }

    protected function http()
    {
        if (!isset($this->http[$this->destination['destination']]))
            $this->http[$this->destination['destination']] = new Client("http://{$this->config['destinations'][$this->destination['destination']]['host']}/");

        return $this->http[$this->destination['destination']];
    }

    public function signalHandler($signal)
    {
        switch($signal) {
        case SIGTERM:
            print "Caught SIGTERM\n";
            $this->cleanup();
            exit;
        case SIGKILL:
            print "Caught SIGKILL\n";
            $this->cleanup();
            exit;
        case SIGINT:
            $this->cleanup();
            print "Caught SIGINT\n";
            exit;
        }
    }
}
