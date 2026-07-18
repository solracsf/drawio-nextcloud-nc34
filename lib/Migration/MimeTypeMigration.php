<?php

declare(strict_types=1);

/**
 * NOTE: This class and its subclasses use \OC::$configDir and \OC::$SERVERROOT,
 * for which there is no public OCP API. Registering custom MIME types via
 * config/mimetypemapping.json is the approach recommended by the Nextcloud
 * documentation and shared by other apps (Keeweb, Mind Map). These usages should
 * be reviewed if Nextcloud provides a public MIME type registration API
 * (https://github.com/nextcloud/server/issues/10131).
 **/

namespace OCA\Drawio\Migration;

use OCP\Files\IMimeTypeLoader;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

abstract class MimeTypeMigration implements IRepairStep
{
    protected const CUSTOM_MIMETYPEMAPPING = 'mimetypemapping.json';
    protected const CUSTOM_MIMETYPEALIASES = 'mimetypealiases.json';

    /**
     * File type icons that app versions up to 4.2.x copied into the Nextcloud
     * core. They make the code integrity check report EXTRA_FILE
     * (https://github.com/jgraph/drawio-nextcloud/issues/70).
     */
    protected const LEGACY_CORE_ICONS = ['drawio.svg', 'dwb.svg'];

    /**
     * MIME type aliases that app versions up to 4.2.x registered so that the
     * icons above were picked up by core/js/mimetypelist.js. The app no longer
     * ships those icons nor regenerates that file, so the aliases have no
     * effect - but they would be baked back into the core file by the next
     * "occ maintenance:mimetype:update-js" run and break the integrity check
     * again.
     */
    protected const LEGACY_ALIASES = [
        'application/x-drawio' => 'drawio',
        'application/x-drawio-wb' => 'dwb',
    ];

    public function __construct(
        protected readonly IMimeTypeLoader $mimeTypeLoader,
        protected readonly IAppConfig $appConfig,
    ) {
    }

    /**
     * Delete the file type icons older versions of this app copied into the
     * Nextcloud core directory.
     */
    protected function removeLegacyCoreIcons(IOutput $output): void
    {
        foreach (self::LEGACY_CORE_ICONS as $icon) {
            $path = \OC::$SERVERROOT . '/core/img/filetypes/' . $icon;

            if (!file_exists($path)) {
                continue;
            }

            if (@unlink($path)) {
                $output->info('Removed ' . $path . ', which an older version of this app copied into the Nextcloud core');
            } else {
                $output->warning('Could not remove ' . $path . '. Delete it manually to fix the code integrity check.');
            }
        }
    }

    /**
     * Merge the given entries into a MIME type configuration file, keeping
     * the entries other apps have registered.
     *
     * @param array<string, mixed> $data
     */
    protected function appendToFile(string $filename, array $data): void
    {
        $config = $this->readFile($filename);

        foreach ($data as $key => $value) {
            $config[$key] = $value;
        }

        $this->writeFile($filename, $config);
    }

    /**
     * Remove the given entries from a MIME type configuration file, keeping
     * the entries other apps have registered. The file is left untouched when
     * it does not exist or contains none of the entries.
     *
     * @param array<string, mixed> $data
     */
    protected function removeFromFile(string $filename, array $data): void
    {
        if (!file_exists($filename)) {
            return;
        }

        $config = $this->readFile($filename);
        $remaining = array_diff_key($config, $data);

        if ($remaining === $config) {
            return;
        }

        $this->writeFile($filename, $remaining);
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(string $filename): array
    {
        if (!file_exists($filename)) {
            return [];
        }

        $config = json_decode((string)file_get_contents($filename), true);

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function writeFile(string $filename, array $config): void
    {
        // An empty array would be encoded as "[]", but Nextcloud expects these
        // files to hold a JSON object
        $data = $config === [] ? new \stdClass() : $config;

        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
