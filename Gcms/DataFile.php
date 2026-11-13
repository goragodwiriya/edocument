<?php
/**
 * @filesource Gcms/DataFile.php
 *
 * Removing a file whose name comes from a table row. A row can hold anything, so a name such as
 * ../../settings/database.php must not reach unlink(): on a shared installation that file belongs to
 * every site.
 *
 * @copyright 2026 Goragod.com
 * @license https://www.kotchasan.com/license/
 */

namespace Gcms;

class DataFile
{
    /**
     * @param string $dir the folder the file must be inside
     * @param string $name its name, or its path below that folder
     * @return bool whether a file was removed
     */
    public static function remove(string $dir, string $name): bool
    {
        $base = realpath($dir);
        $path = $name === '' ? false : realpath(rtrim($dir, '/').'/'.$name);
        if ($base === false || $path === false || strpos($path, $base.DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }
        // Not .htaccess or the like: those guard the folder.
        if (basename($path)[0] === '.' || !is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * @param string $stored a path kept relative to ROOT_PATH, such as a category icon. It must lie in
     * this site's data folder.
     * @return bool whether a file was removed
     */
    public static function removeStored(string $stored): bool
    {
        if (strpos($stored, DATA_FOLDER) !== 0) {
            return false;
        }

        return self::remove(ROOT_PATH.DATA_FOLDER, substr($stored, strlen(DATA_FOLDER)));
    }
}
