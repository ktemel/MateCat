<?php
/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 2/15/16
 * Time: 2:39 PM
 */

namespace Features\ReviewImproved\Model;

use Database ;

class QualityReportDao extends \DataAccess_AbstractDao {

    protected function _buildResult( $result_array ) {

    }


    public function getAverages( \Chunks_ChunkStruct $chunk ) {
        $sql = <<<SQL

      SELECT
        ROUND( AVG( time_to_edit ) ) AS avg_time_to_edit,
        ROUND( AVG( edit_distance ) ) AS avg_edit_distance

      FROM segment_translations st

      JOIN jobs
        ON jobs.id = st.id_job
        AND jobs.password = :password
        AND jobs.id = :id_job

      JOIN segments s
        ON s.id = st.id_segment
        AND s.id >= jobs.job_first_segment
        AND s.id <= jobs.job_last_segment

      JOIN files_job fj
        ON st.id_job = fj.id_job
           AND s.id_file = fj.id_file

      JOIN files f ON f.id = fj.id_file

        WHERE show_in_cattool
SQL;

        $conn  = Database::obtain()->getConnection();
        $stmt = $conn->prepare( $sql );
        $stmt->setFetchMode( \PDO::FETCH_ASSOC );

        $stmt->execute( array(
            'id_job'   => $chunk->id,
            'password' => $chunk->password
        ) );

        return $stmt->fetch();

    }
    /**
     * @param \Chunks_ChunkStruct $chunk
     *
     * @return array
     */
    public static function getSegmentsForQualityReport( \Chunks_ChunkStruct $chunk ) {

        $sql = <<<SQL

SELECT

  f.id AS file_id,
  f.filename AS file_filename,

  otv.translation AS original_translation,

  st.version_number,
  st.translation as translation,
  st.status as translation_status,
  st.edit_distance as edit_distance,
  st.time_to_edit  as time_to_edit,

  s.id AS segment_id,
  s.segment AS segment_source,

  comments.id as comment_id,
  comments.comment as comment_comment,
  comments.create_date as comment_create_date,
  comments.uid as comment_uid,

  issues.id as issue_id,
  issues.create_date as issue_create_date,
  issues.replies_count as issue_replies_count,
  
  -- start_offset and end_offset were introduced for DQF. We are taking for granted a string with 
  -- both start_node and end_node equal to 0 ( no tags in target string ). 
  issues.start_offset  as issue_start_offset,
  issues.end_offset    as issue_end_offset,

  qa_categories.label   as issue_category,
  qa_categories.options as category_options,
  
  issues.severity     as issue_severity,
  issues.comment      as issue_comment,
  issues.target_text  as target_text,
  issues.uid          as issue_uid,
  
  translation_warnings.scope as warning_scope, 
  translation_warnings.data as warning_data, 
  translation_warnings.severity as warning_severity

FROM segment_translations st

  JOIN jobs
    ON jobs.id = st.id_job
    AND jobs.password = :password
    AND jobs.id = :id_job

  JOIN segments s
    ON s.id = st.id_segment
    AND s.id >= jobs.job_first_segment
    AND s.id <= jobs.job_last_segment

  JOIN files_job fj
    ON st.id_job = fj.id_job
       AND s.id_file = fj.id_file

  JOIN files f ON f.id = fj.id_file

  LEFT JOIN segment_translation_versions otv
    ON otv.id_segment = st.id_segment
       AND otv.version_number = 0

  LEFT JOIN qa_entries issues
    ON issues.id_job = jobs.id
    AND issues.id_segment = s.id
    AND issues.translation_version = st.version_number

  LEFT JOIN qa_entry_comments comments
    ON comments.id_qa_entry = issues.id

  LEFT JOIN qa_categories
    ON issues.id_category = qa_categories.id
    
  LEFT JOIN translation_warnings 
    ON translation_warnings.id_segment = s.id 
      AND translation_warnings.id_job = jobs.id 

WHERE

s.show_in_cattool AND 
( 
  st.status IN ( :approved, :rejected ) 
  OR 
  translation_warnings.id_segment IS NOT NULL
)

ORDER BY f.id, s.id, issues.id, comments.id

;

SQL;

        $conn  = Database::obtain()->getConnection();
        $stmt = $conn->prepare( $sql );
        $stmt->setFetchMode( \PDO::FETCH_ASSOC );

        $stmt->execute( array(
                'approved' => \Constants_TranslationStatus::STATUS_APPROVED,
                'rejected' => \Constants_TranslationStatus::STATUS_REJECTED,
                'id_job'   => $chunk->id,
                'password' => $chunk->password
        ) );

        return $stmt->fetchAll();

    }

    /**
     * @param \Chunks_ChunkStruct $chunk
     *
     * @return array
     */
    public static function getIssuesBySegments( $segments_id ) {

        $prepare_str_segments_id = str_repeat( 'UNION SELECT ? ', count( $segments_id ) - 1);

        $sql = "SELECT

  issues.id_segment as id_segment,
  issues.id as issue_id,
  issues.create_date as issue_create_date,
  issues.replies_count as issue_replies_count,
  
  -- start_offset and end_offset were introduced for DQF. We are taking for granted a string with 
  -- both start_node and end_node equal to 0 ( no tags in target string ). 
  issues.start_offset  as issue_start_offset,
  issues.end_offset    as issue_end_offset,

  qa_categories.label   as issue_category,
  qa_categories.options as category_options,
  
  issues.severity     as issue_severity,
  issues.comment      as issue_comment,
  issues.target_text  as target_text,
  issues.uid          as issue_uid,
  
  translation_warnings.scope as warning_scope, 
  translation_warnings.data as warning_data, 
  translation_warnings.severity as warning_severity

FROM  qa_entries issues

  LEFT JOIN qa_entry_comments comments
    ON comments.id_qa_entry = issues.id

  LEFT JOIN qa_categories
    ON issues.id_category = qa_categories.id
    
  LEFT JOIN translation_warnings 
    ON translation_warnings.id_segment = issues.id_segment 
      
  JOIN ( 
		SELECT ? as id_segment
		".$prepare_str_segments_id."
) AS SLIST USING( issues.id_segment )";

        $conn = Database::obtain()->getConnection();
        $stmt = $conn->prepare( $sql );
        $stmt->setFetchMode( \PDO::FETCH_CLASS, '\DataAccess\ShapelessConcreteStruct' );

        $stmt->execute( $segments_id );

        return $stmt->fetchAll();
    }

}