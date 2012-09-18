<?php

/*
 * This file is copied from the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license MIT
 */

namespace Composer\Autoload;

/**
 * ClassMapGenerator
 *
 * @author Gyula Sallai <salla016@gmail.com>
 */
class ClassMapGenerator
{
    /**
     * Generate a class map file
     *
     * @param Traversable $dirs Directories or a single path to search in
     * @param string      $file The name of the class map file
     */
    public static function dump($dirs, $file)
    {
        $maps = array();

        foreach ($dirs as $dir) {
            $maps = array_merge($maps, static::createMap($dir));
        }

        file_put_contents($file, sprintf('<?php return %s;', var_export($maps, true)));
    }

    /**
     * Iterate over all files in the given directory searching for classes
     *
     * @param Iterator|string $dir       The directory to search in or an iterator
     * @param string          $whitelist Regex that matches against the file path
     *
     * @return array A class map array
     */
    public static function createMap($dir, $whitelist = null)
    {
        if (is_string($dir)) {
            if (is_file($dir)) {
                $dir = array(new \SplFileInfo($dir));
            } else {
                $dir = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            }
        }

        $map = array();

        foreach ($dir as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getRealPath();

            if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            if ($whitelist && !preg_match($whitelist, strtr($path, '\\', '/'))) {
                continue;
            }

            $classes = self::findClasses($path);

            foreach ($classes as $class) {
                $map[$class] = $path;
            }

        }

        return $map;
    }

    /**
     * Extract the classes in the given file
     *
     * @param string $path The file to check
     *
     * @return array The found classes
     */
    private static function findClasses($path)
    {
        $contents = file_get_contents($path);
        try {
            if (!preg_match('{\b(?:class|interface|trait)\b}i', $contents)) {
                return array();
            }
            $tokens   = token_get_all($contents);
        } catch (\Exception $e) {
            throw new \RuntimeException('Could not scan for classes inside '.$path.": \n".$e->getMessage(), 0, $e);
        }
        $T_TRAIT  = version_compare(PHP_VERSION, '5.4', '<') ? -1 : T_TRAIT;

        $classes = array();

        $namespace = '';
        for ($i = 0, $max = count($tokens); $i < $max; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                continue;
            }

            $class = '';

            switch ($token[0]) {
                case T_NAMESPACE:
                    $namespace = '';
                    // If there is a namespace, extract it
                    while (($t = $tokens[++$i]) && is_array($t)) {
                        if (in_array($t[0], array(T_STRING, T_NS_SEPARATOR))) {
                            $namespace .= $t[1];
                        }
                    }
                    $namespace .= '\\';
                    break;
                case T_CLASS:
                case T_INTERFACE:
                case $T_TRAIT:
                    // Find the classname
                    while (($t = $tokens[++$i]) && is_array($t)) {
                        if (T_STRING === $t[0]) {
                            $class .= $t[1];
                        } elseif ($class !== '' && T_WHITESPACE == $t[0]) {
                            break;
                        }
                    }

                    $classes[] = ltrim($namespace . $class, '\\');
                    break;
                default:
                    break;
            }
        }

        return $classes;
    }
}
