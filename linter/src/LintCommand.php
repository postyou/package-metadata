<?php

declare(strict_types=1);

/*
 * Contao Package Metadata Linter
 *
 * @author     Yanick Witschi <yanick.witschi@terminal42.ch>
 * @license    MIT
 */

namespace Contao\PackageMetaDataLinter;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use JsonSchema\Validator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class LintCommand extends Command
{
    /**
     * @var SymfonyStyle
     */
    private $io;

    /**
     * @var SpellChecker
     */
    private $spellChecker;

    protected function configure()
    {
        $this
            ->setName('app:lint')
            ->setDescription('Lint all the metadata.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->io->title('Contao Package metadata linter');

        $this->spellChecker = new SpellChecker(__DIR__.'/../whitelists');

        $finder = new Finder();
        $finder->files()->in(__DIR__.'/../../meta')->depth('== 2')->name('*.{yaml,yml}');

        foreach ($finder as $file) {
            $package = basename(\dirname($file->getPath())).'/'.basename($file->getPath());
            $language = str_replace(['.yaml', '.yml'], '', $file->getBasename());

            $content = file_get_contents($file->getPath().'/'.$file->getFilename());

            // Line ending
            if (!("\n" === substr($content, -1) && "\n" !== substr($content, -2))) {
                $this->error($package, $language, 'All files must end by a singe new line.');

                return 1;
            }

            try {
                $content = Yaml::parse($content);
            } catch (ParseException $e) {
                $this->error($package, $language, 'The YAML file is invalid');

                return 1;
            }

            // Language
            if (!isset($content[$language])) {
                $this->error($package, $language, 'The language key in the YAML file does not match the specified language file name.');

                return 1;
            }

            // Validate for private package
            $requiresHomepage = $this->isPrivatePackage($package);

            // Content
            if (!$this->validateContent($package, $language, $content[$language], $requiresHomepage)) {
                $this->error($package, $language, 'The YAML file contains invalid data.');

                return 1;
            }
        }

        $this->io->success('All checks successful!');

        return 0;
    }

    private function isPrivatePackage(string $package): bool
    {
        static $packageCache = [];

        if (isset($packageCache[$package])) {
            return $packageCache[$package];
        }

        try {
            $this->io->writeln('Checking if package exists on packagist.org: '.$package, OutputInterface::VERBOSITY_DEBUG);
            $this->getJson('https://repo.packagist.org/p/'.$package.'.json');
        } catch (RequestException $e) {
            if (404 !== $e->getResponse()->getStatusCode()) {
                // Shouldn't happen, throw
                throw $e;
            }

            return true;
        }

        return $packageCache[$package] = false;
    }

    private function validateContent(string $package, string $language, array $content, bool $requiresHomepage): bool
    {
        $data = json_decode(json_encode($content));

        $schemaData = json_decode(file_get_contents(\dirname(__DIR__).'/schema.json'), true);
        if ($requiresHomepage) {
            $schemaData['required'] = ['homepage'];
        }

        $validator = new Validator();
        $validator->validate($data, $schemaData);

        foreach ($validator->getErrors() as $error) {
            $message = $error['message'].(('' !== $error['property']) ? (' ['.$error['property'].']') : '');
            $this->io->error($message);
        }

        // Spellcheck certain properties
        foreach (['title', 'description'] as $key) {
            if (!isset($content[$key])) {
                continue;
            }

            $errors = $this->spellChecker->spellCheck($content[$key], $language);
            if (0 !== \count($errors)) {
                $this->error(
                    $package,
                    $language,
                    sprintf(
                        'Property "%s" does not pass the spell checker. Either update the whitelist or fix the spelling :) Errors: %s',
                        $key,
                        implode(', ', $errors)
                    )
                );

                return false;
            }
        }

        return $validator->isValid();
    }

    private function error(string $package, string $language, string $message)
    {
        $this->io->error(sprintf('[Package: %s; Language: %s]: %s',
            $package,
            $language,
            $message
        ));
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function getJson(string $uri): array
    {
        $client = new Client();

        $response = $client->request('GET', $uri);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('Response error. Status code %s', $response->getStatusCode()));
        }

        return (array) json_decode($response->getBody()->getContents(), true);
    }
}
