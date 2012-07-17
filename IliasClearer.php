<?php
/**
 * This class provides some functions for clearing up the messed ILIAS categories
 * @author  Daniel Kabel <daniel.kabel@me.com>
 * @version 1.0
 */
class IliasClearer
{
    /**
     * Edit the next four variables to your needs
     */
    // Full path to ILIAS installation (e.g. /srv/www/ilias)
    private $PATH_TO_ILIAS_INSTALLATION = '.';
    // ILIAS client name (e.g. unihalle)
    private $ILIAS_CLIENT = 'ilias';
    // ref id of main category
    private $ILIAS_MAIN_CATEGORY_ID = 0;
    // ref id of archive category
    private $ILIAS_ARCHIVE_CATEGORY_ID = 0;

    private $ILIAS_TREE_ID = 1;
    private $m_db;
    
    /**
     * Checks config variables and files, opens Database connection
     */
    public function __construct($argv)
    {
        // Check if path to ILIAS is correct
        if (!is_dir($this->PATH_TO_ILIAS_INSTALLATION)) {
            echo 'ILIAS Installtion not found'."\n";
            exit();
        }
        
        // Check if ILIAS client exists
        if (!is_file($this->PATH_TO_ILIAS_INSTALLATION.'/data/'.$this->ILIAS_CLIENT.'/client.ini.php')) {
            echo 'client.ini.php for client "'.$this->ILIAS_CLIENT.'" not found'."\n";
            exit();
        }
        
        // Parse ILIAS client.ini
        $iliasIni = parse_ini_file($this->PATH_TO_ILIAS_INSTALLATION.'/data/'.$this->ILIAS_CLIENT.'/client.ini.php', true);
        if ($iliasIni['db']['type'] != 'mysql') {
            echo 'Unsupported database type "'.$iliasIni['db']['type'].'"'."\n";
            exit();
        }
        
        // Open Database
        $this->m_db = new mysqli($iliasIni['db']['host'], $iliasIni['db']['user'], 
                               $iliasIni['db']['pass'], $iliasIni['db']['name']);
        if (mysqli_connect_errno()) {
            $this->m_db = null;
            echo 'Database connection failed: '.mysqli_connect_error()."\n";
            exit();
        }

        // parse command line and execute given action or print usage info        
        $action = (count($argv) == 2) ? $argv[1] : '';
        switch ($action) {
            case 'archive':
                $this->moveCoursesToArchive();
                break;
            case 'listEmptyCourses':
                $this->listEmptyCourses();
                break;
            default:
                echo 'Usage: php '.$argv[0].' <archive|listEmptyCourses>'."\n\n";
                echo '  archive         : moves courses created before the last year to archive'."\n";
                echo '  listEmptyCourses: print a list of courses with no contents'."\n";
                echo "\n";
                exit();
        }
    }
    
    /**
     * Closes Database connection
     */
    public function __destruct()
    {
        if ($this->m_db) {
            $this->m_db->close();
        }
    }
    
    /**
     * Moves all courses created earlier than the last year to archive
     */
    private function moveCoursesToArchive()
    {
        echo 'Moving old courses to archive...'."\n\n";
        foreach ($this->activeCourseYears() as $year) {
            // skip the last 2 years (including this year)    
            if ($year > date('Y')-2) {
                continue;
            }
            
            $targetId = $this->archiveCategoryIdByTitle($year);
            if ($targetId === false) {
                echo 'Archive category for year '.$year.' doesn\'t exist, skipping'."\n";
                continue;
            }
            
            $courses = $this->coursesCreatedInYear($year);
            foreach ($courses as $sourceId) {
                $this->moveTree($sourceId, $targetId);
            }
            echo 'Moved '.count($courses).' courses to Archive '.$year."\n";
        }
    }
    
    /**
     * Prints a list of courses with no content
     */
    private function listEmptyCourses()
    {
        $courses = $this->emptyCourses();
        echo 'Listing empty courses'."\n\n";
        echo 'ref_id'."\t".'| title'."\n";
        echo '-----------------------------------'."\n";
        foreach ($courses as $course) {
            echo $course['ref_id']."\t".'| '.$course['title']."\n";
        }
    }
    
    /**
     * Moves a tree into another one
     * @param $sourceId ID of tree to move
     * @param $targetId ID of tree where the source should be placed
     */
    private function moveTree($sourceId, $targetId)
    {
        // Receive node infos for source and target
        $source = array();
        $target = array();

        $stmt = $this->m_db->prepare('SELECT child, parent, lft, rgt, depth FROM tree WHERE (child=? OR child=?) AND tree=?');
        $stmt->bind_param('iii', $sourceId, $targetId, $this->ILIAS_TREE_ID);
        $stmt->execute();
        $stmt->bind_result($child, $parent, $lft, $rgt, $depth);
        while ($stmt->fetch()) {
            if ($child == $sourceId) {
                $source = array(
                    'lft'    => $lft,
                    'rgt'    => $rgt,
                    'depth'  => $depth,
                    'parent' => $parent
                );
            } else if ($child == $targetId) {
                $target = array(
                    'lft'    => $lft,
                    'rgt'    => $rgt,
                    'depth'  => $depth,
                    'parent' => $parent
                );
            }
        }
        $stmt->close();
        
        if (empty($source) || empty($target)) {
            echo 'Objects not found in tree'."\n";
            exit();
        }
        
        // Check target not child of source
        if ($target['lft'] >= $source['lft'] && $target['rgt'] <= $source['rgt']) {
            echo 'Error moving node: Target is child of source'."\n";
            exit();
        }
        
        // Now spread the tree at the target location. After this update the table should be still in a consistent state.
        $spreadDiff = $source['rgt']-$source['lft']+1;
        
        $stmt = $this->m_db->prepare('UPDATE tree SET 
                                      lft=CASE WHEN lft>? THEN lft+? ELSE lft END,
                                      rgt=CASE WHEN rgt>=? THEN rgt+? ELSE rgt END
                                    WHERE tree=?');
        $stmt->bind_param('iiiii', $target['rgt'], $spreadDiff, $target['rgt'], $spreadDiff, $this->ILIAS_TREE_ID);
        $stmt->execute();
        $stmt->close();
        
        $whereOffset = 0;
        $moveDiff = 0;
        $depthDiff = $target['depth']-$source['depth']+1;

        // Maybe the source node has been updated, too.
        if ($source['lft'] > $target['rgt']) {
            $whereOffset = $spreadDiff;
            $moveDiff = $target['rgt']-$source['lft']-$spreadDiff;
        } else {
            $whereOffset = 0;
            $moveDiff = $target['rgt']-$source['lft'];
        }
        
        $sourceLftAndOffset = $source['lft']+$whereOffset;
        $sourceRgtAndOffset = $source['rgt']+$whereOffset;
        $stmt = $this->m_db->prepare('UPDATE tree SET 
                                      parent=CASE WHEN parent=? THEN ? ELSE parent END,
                                      rgt=rgt+?,
                                      lft=lft+?,
                                      depth=depth+?
                                    WHERE lft>=? AND rgt<=? AND tree=?');
        $stmt->bind_param('iiiiiiii', $source['parent'], $targetId, $moveDiff, $moveDiff, $depthDiff, 
                                      $sourceLftAndOffset, $sourceRgtAndOffset, $this->ILIAS_TREE_ID);
        $stmt->execute();
        $stmt->close();
        
        // done: close old gap
        $stmt = $this->m_db->prepare('UPDATE tree SET
                                      lft=CASE WHEN lft>=? THEN lft-? ELSE lft END,
                                      rgt=CASE WHEN rgt>=? THEN rgt-? ELSE rgt END
                                    WHERE tree=?');
        $stmt->bind_param('iiiii', $sourceLftAndOffset, $spreadDiff, $sourceRgtAndOffset, 
                                   $spreadDiff, $this->ILIAS_TREE_ID);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Gets all years of courses in main category
     * @return array List of years
     */
    private function activeCourseYears()
    {
        $years = array();
        $stmt = $this->m_db->prepare("SELECT DATE_FORMAT(OD.create_date, '%Y')
                                    FROM tree T 
                                    JOIN object_reference OREF ON OREF.ref_id=T.child
                                    JOIN object_data OD ON OREF.obj_id=OD.obj_id
                                    WHERE T.tree=? AND T.parent=? AND OD.type='crs'
                                    GROUP BY DATE_FORMAT(OD.create_date, '%Y')");
        $stmt->bind_param('ii', $this->ILIAS_TREE_ID, $this->ILIAS_MAIN_CATEGORY_ID);
        $stmt->execute();
        $stmt->bind_result($year);
        while ($stmt->fetch()) {
            $years[] = $year;
        }
        $stmt->close();
        return $years;
    }
    
    /**
     * Returns ref_id of object with name $title in archive
     * @param $title Title of category
     * @return mixed ref_id of object, if not found false
     */
    private function archiveCategoryIdByTitle($title)
    {
        $childId = false;
        $stmt = $this->m_db->prepare("SELECT T.child
                                    FROM tree T 
                                    JOIN object_reference OREF ON OREF.ref_id=T.child
                                    JOIN object_data OD ON OREF.obj_id=OD.obj_id
                                    WHERE T.tree=? AND T.parent=? AND OD.title=? AND OD.type='cat'");
        $stmt->bind_param('iis', $this->ILIAS_TREE_ID, $this->ILIAS_ARCHIVE_CATEGORY_ID, $title);
        $stmt->execute();
        $stmt->bind_result($child);
        if ($stmt->fetch()) {
            $childId = $child;
        }
        $stmt->close();
        return $childId;
    }
    
    /**
     * Returns all course ref_ids of courses created in $year
     * @param $year Year of creation
     * @return array List of course ref_ids
     */
    private function coursesCreatedInYear($year)
    {
        $courseIds = array();
        $stmt = $this->m_db->prepare("SELECT T.child
                                    FROM tree T 
                                    JOIN object_reference OREF ON OREF.ref_id=T.child
                                    JOIN object_data OD ON OREF.obj_id=OD.obj_id
                                    WHERE T.tree=? AND T.parent=? AND OD.type='crs' AND DATE_FORMAT(OD.create_date, '%Y')=?");
        $stmt->bind_param('iii', $this->ILIAS_TREE_ID, $this->ILIAS_MAIN_CATEGORY_ID, $year);
        $stmt->execute();
        $stmt->bind_result($childId);
        while ($stmt->fetch()) {
            $courseIds[] = $childId;
        }
        $stmt->close();
        return $courseIds;
    }
    
    /**
     * Get a list of courses with no contents
     * @return array List of courses
     */
    private function emptyCourses()
    {
        $courses = array();
        $stmt = $this->m_db->prepare("SELECT T.child, OD.title
                                    FROM tree T 
                                    JOIN object_reference OREF ON OREF.ref_id=T.child
                                    JOIN object_data OD ON OD.obj_id=OREF.obj_id
                                    WHERE T.parent=? AND OD.type='crs' AND
                                    (
                                        SELECT COUNT(*) 
                                        FROM tree Tin 
                                        JOIN object_reference OREFin ON OREFin.ref_id=Tin.child
                                        JOIN object_data ODin ON ODin.obj_id=OREFin.obj_id
                                        WHERE Tin.parent=T.child AND ODin.type!='rolf'
                                    )=0");
        $stmt->bind_param('i', $this->ILIAS_MAIN_CATEGORY_ID);
        $stmt->execute();
        $stmt->bind_result($child, $title);
        while ($stmt->fetch()) {
            $courses[] = array(
                'ref_id' => $child,
                'title'  => $title
            );
        }
        $stmt->close();
        return $courses;
    }
}

$iliasClearer = new IliasClearer($argv);

