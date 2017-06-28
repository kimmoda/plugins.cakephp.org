<?php
namespace App\Job;

use App\Model\Entity\Package;
use App\Traits\LogTrait;
use Cake\Datasource\ModelAwareTrait;
use Cake\Collection\Collection;
use Cake\Filesystem\File;
use Cake\Filesystem\Folder;
use Cake\Utility\Hash;
use josegonzalez\Queuesadilla\Job\Base;

class ClassifyJob
{
    use LogTrait;

    use ModelAwareTrait;

    protected $_fileRegex = array(
        'model' => array('/Model\/([\w]+).php$/'),
        'entity' => array('/Model\/Entity\/([\w]+).php$/'),
        'table' => array('/Model\/Table\/([\w]+).php$/'),
        'view' => array('/View\/([\w]+)View.php$/'),
        'controller' => array('/Controller\/([\w]+)Controller.php$/'),
        'component' => array('/Controller\/Component\/([\w]+)Controller.php$/'),
        'behavior' => array('/Model\/Behavior\/([\w]+)Behavior.php$/'),
        'helper' => array('/View\/Helper\/([\w]+)Helper.php$/'),
        'shell' => array('/Console\/Command\/([\w]+)Shell.php$/'),
        'locale' => array('/Locale\/([\w\/]+).pot$/', '/Locale\/([\w\/]+).po$/'),
        'datasource' => array('/Model\/Datasource\/([\w]+)Source.php$/', '/Model\/Database\/([\w]+).php$/'),
        'tests' => array('/Test\/Case\/([\w\/]+)Test.php$/'),
        'fixture' => array('/Test\/Fixture\/([\w]+)Fixture.php$/'),
        'themed' => array('/View\/Themed\/([\w\/]+).ctp$/', '/Template\/Themed\/([\w\/]+).ctp$/'),
        'elements' => array('/View\/Elements\/([\w\/]+).ctp$/', '/Template\/Element\/([\w\/]+).ctp$/'),
        'cell' => array('/View\/Cell\/([\w\/]+).php$/'),
        'vendor' => array('/Vendor\/([\w]+).php$/'), //
        'lib' => array('/Lib\/([\w\/]+).php$/'),
        'log' => array('/Log\/Engine\/([\w]+).php$/'),
        'panel' => array('/Lib\/Panel\/([\w]+)Panel.php$/'),
        'config' => array('/Config\/([\w_\/]+).php$/'),
        'resource' => array('/.js$/', '/.css$/', '/.bmp$/', '/.gif$/', '/.jpeg$/', '/.jpg$/', '/.png$/'),
        'composer' => array('/composer.json$/'),
        'travis' => array('/^.travis.yml$/'),
        'readme' => array('/^README.(md|markdown|textile|rst)$/'),
        'license' => array('/^LICENSE(?:\.txt)?$/i'),
        'plugin' => array(),
        'app' => array('/app\//'),
    );

    public function perform(Base $job)
    {
        $packageId = $job->data('package_id');
        if (empty($packageId)) {
            $this->error('No package id specified');

            return false;
        }

        $this->loadModel('Packages');
        $this->loadModel('Tagged');
        $this->loadModel('Tags');

        $package = $this->Packages->find()->contain([
            'Categories',
            'Maintainers',
        ])->where(['Packages.id' => $packageId])->first();

        if (empty($package)) {
            $this->error(sprintf('No package found in database for %d', $packageId));

            return false;
        }

        $this->info(sprintf('Updating: %s', $package->id));
        $skipTags = [
            'has:composer',
            'has:elements',
            'has:fixture',
            'has:lib',
            'has:license',
            'has:locale',
            'has:readme',
            'has:travis',
            'has:vendor',
        ];

        if (!$package->isCloned()) {
            return;
        }

        list($files, $tags) = $this->classify($package);
        $this->Tagged->deleteAll([
            'foreign_key' => $package->id,
            'model' => 'Package',
        ]);
        foreach ($tags as $tagStr) {
            $tag = $this->Tags->add($tagStr);
            $this->Tagged->addTagToPackage($tag, $package);
        }
        $package->tags = implode(',', $tags);
        if (strlen($package->tags) >= 255) {
            $_tags = [];
            foreach ($tags as $tagStr) {
                if ($this->startsWith('keyword:', $tagStr)) {
                    continue;
                }
                if (in_array($tagStr, $skipTags)) {
                    continue;
                }
                $_tags[] = $tagStr;
            }
            $package->tags = implode(',', $_tags);
        }

        $this->info($package->tags);
        $this->Packages->save($package);
    }

    protected function classify($package)
    {
        $path = $package->cloneDir();
        $folder = new Folder($path);
        $files = [];
        foreach ($folder->findRecursive() as $file) {
            if ($this->startsWith(sprintf('%s/.git/', $path), $file)) {
                continue;
            }
            $files[$file] = $this->fetchType(str_replace($path . '/', '', $file));
        }

        $tags = array_values($files);
        $composerData = $this->composerData($package, $path, $tags);
        $tags = $this->cleanupTags($path, $composerData);

        return [
            array_keys($files),
            $tags,
        ];
    }

    protected function cleanupTags($path, $composerData)
    {
        $tags = (array)(new Collection($composerData['tags']))
            ->reject(function($tag) {
                return !is_string($tag);
            })
            ->reject(function($tag) {
                return in_array($tag, ['keyword:cake', 'keyword:cakephp']) || preg_match('/\s/', $tag) || $tag === '';
            })
            ->map(function($tag) {
                return strtolower($tag);
            })
            ->map(function($tag) {
                return strpos($tag, ':') === false ? sprintf('has:%s', $tag) : $tag;
            })
            ->toArray();

        if ($composerData['version'] !== null) {
            $tags[] = sprintf('version:%s', $composerData['version']);
        }

        $license = $this->license($path, $composerData);
        if (!empty($license)) {
            $tags[] = $license;
        }

        $tags = array_flip($tags);
        if (!$composerData['composer'] && in_array('composer', $tags)) {
            unset($tags['composer']);
        }
        if (in_array('license', $tags)) {
            unset($tags['license']);
        }
        return array_unique(array_values(array_flip($tags)));
    }

    protected function license($path, $composerData)
    {
        if (!empty($composerData['license'])) {
            if ($composerData['license'] === 'lgpl-3.0+') {
                $composerData['license'] = 'lgpl-3.0';
            }
            return sprintf('license:%s', $composerData['license']);
        }

        if (!in_array('license', $composerData['tags'])) {
            return null;
        }

        $licenseFilenames = ['LICENSE', 'LICENSE.txt', 'LICENSE.TXT'];
        $licenses = ['apache', 'mit', 'bsd-3-clause', 'bsd-2-clause'];
        foreach ($licenseFilenames as $filename) {
            $licenseFile = sprintf('%s/%s', $path, $filename);
            if (!file_exists($licenseFile)) {
                continue;
            }

            $file = new File($licenseFile);
            $contents = strtolower($file->read());
            foreach ($licenses as $license) {
                if (strpos($contents, $license) !== false) {
                    return sprintf('license:%s', $license);
                }
            }
        }

        return null;
    }

    protected function version($package, $composerData, $composerContents)
    {
        $version = '1.3';
        $cake2Tags = [
            'model', 'view', 'controller',
            'component', 'behavior', 'helper',
            'shell', 'themed', 'log', 'panel', 'config',
            'locale', 'datasource',
            'tests', 'fixture',
        ];
        $cake3Tags = ['entity', 'table', 'cell'];
        foreach ($cake2Tags as $cake2Tag) {
            if (in_array($cake2Tag, $composerData['tags'])) {
                $version = '2';
            }
        }
        foreach ($cake3Tags as $cake3Tag) {
            if (in_array($cake3Tag, $composerData['tags'])) {
                $version = '3';
            }
        }

        if ($version == '1.3') {
            $cake2Versions = ['2.x', '2.0', '2.1', '2.2', '2.3', '2.4', '2.5', '2.6', '2.7', '2.8', '2.9', '2.10'];
            foreach ($cake2Versions as $cake2Version) {
                if (strpos($package->description, $cake2Version) === false) {
                    continue;
                }
                $version = '2';
                break;
            }
        }

        $hasInstallerName = strlen(Hash::get($composerContents, 'extra.installer-name', '')) > 0;
        if ($hasInstallerName) {
            $version = '2';
        }

        $dependsOnComposerInstalllers = strlen(Hash::get($composerContents, 'require.composer/installers', '')) > 0;
        $devDependsOnComposerInstalllers = strlen(Hash::get($composerContents, 'require-dev.composer/installers', '')) > 0;
        if ($dependsOnComposerInstalllers || $devDependsOnComposerInstalllers) {
            $version = '2';
        }

        $dependsOnCake = Hash::get($composerContents, 'require.cakephp/cakephp', '');
        $devDependsOnCake = Hash::get($composerContents, 'require-dev.cakephp/cakephp', '');
        if (strlen($dependsOnCake) > 0 || strlen($devDependsOnCake) > 0) {
            $version = '2';
            $version3Starters = ['3.', '~3.', '^3.', '>=3.'];
            $version4Starters = ['4.', '~4.', '^4.', '>=4.'];

            foreach ([3 => $version3Starters, 4 => $version4Starters] as $starterVersion => $starters) {
                foreach ($starters as $starter) {
                    if ($this->startsWith($starter, $dependsOnCake)) {
                        $version = $starterVersion;
                    }
                }
                foreach ($starters as $starter) {
                    if ($this->startsWith($starter, $devDependsOnCake)) {
                        $version = $starterVersion;
                    }
                }
            }
        }

        return $version;
    }

    protected function composerContents($composerData, $composerPath)
    {
        if (!in_array('composer', $composerData['tags']) || !file_exists($composerPath)) {
            return [];
        }

        $createPath = false;
        $file = new File($composerPath, $createPath);
        $contents = json_decode($file->read(), true);

        if (empty($contents)) {
            return [];
        }

        return $contents;
    }

    protected function composerData($package, $path, $tags)
    {
        $composerPath = sprintf('%s/composer.json', $path);
        $composerData = [
            'path' => $path,
            'composer' => false,
            'license' => null,
            'tags' => $tags,
            'version' => null,
        ];

        $composerContents = $this->composerContents($composerData, $composerPath);
        $version = $this->version($package, $composerData, $composerContents);
        $composerData['version'] = $version;

        if (empty($composerContents)) {
            return $composerData;
        }

        $license = Hash::get($composerContents, 'license');
        if (is_array($license)) {
            $license = $license[0];
        }
        $composerData['license'] = strtolower($license);
        $composerData['version'] = $version;

        $keywords = (new Collection(Hash::get($composerContents, 'keywords', [])))
            ->map(function($tag) {
                return sprintf('keyword:%s', $tag);
            })
            ->toArray();

        $composerData['tags'] = array_merge($keywords, $composerData['tags']);
        return $composerData;
    }

    protected function fetchType($filename)
    {
        $type = null;
        foreach ($this->_fileRegex as $_type => $regexes) {
            if (empty($regexes)) {
                continue;
            }

            foreach ($regexes as $regex) {
                if (preg_match($regex, $filename)) {
                    $type = $_type;
                    break;
                }
            }
        }

        return $type;
    }

    /**
     * Returns if value starts with a value
     *
     * @param string $string The value to search for
     * @param string $line   The line to test
     *
     * @return bool Returns if the line starts with value
     */
    protected function startsWith($string, $line)
    {
        return $string === "" || strrpos($line, $string, -strlen($line)) !== false;
    }
}