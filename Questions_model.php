<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Question_model extends CI_Model
{

    public function getQuestions($ids = null, $offset = 0, $filter = null, $answersOptions = false, $questionOptions = false)
    {
        $this->db->select('bq.*');

        if($ids)
        {
            if(is_array($ids))
                $this->db->where_in('bq.id', $ids);
            else
                $this->db->where('bq.id', $ids);
        }

        if($filter['subjects'])
        {
            $this->db->join('bank_questions_subjects bqs', 'bqs.question_id=bq.id');
            $this->db->where_in('bqs.subject_id', $filter['subjects']);
            $this->db->group_by('bq.id');
        }

        if($filter['test_id'])
        {
            $this->db->join('bank_questions_students_tests bqst', 'bqst.question_id=bq.id');
            $this->db->where_in('bqst.test_id',  is_array($filter['test_id']) ? $filter['test_id'] : [$filter['test_id']]);
            $this->db->group_by('bq.id');
        }

        $questions = $this->db->get('bank_questions bq')->result_array();

        $questions = $questionOptions['transform'] ? $this->transformQuestions($questions) : $questions;

        if($answersOptions)
        {
            foreach ($questions as &$question)
            {
                $answers = $this->getAnswersByQuestion($question['id']);
                $question['answers'] =  $answersOptions['transform'] ? $this->transformAnswers($answers) : $answers;
            }


        }

        return $questions;
    }

    public function clearAssignedTestInfo($test_id)
    {
        $this->db
            ->where('test_id', $test_id)
            ->delete('bank_subjects_tests');

        $this->db
            ->where('test_id', $test_id)
            ->delete('bank_questions_students_tests');

    }

    public function updateQuestion($id, $data)
    {
        $item_data = $data;

        unset($item_data['answers'], $item_data['subjects'], $item_data['correct_keys']);

        $this->db
            ->where('id', $id)
            ->update('bank_questions', $item_data);

        $this->deleteAnswersByQuestion($id);

        if($data['answers'])
        {
            $this->createAnswers($id, $data['answers'], $data['correct_keys']);
        }

        $this->clearQuestionSubjects($id);

        if($data['subjects'])
        {
            $this->assignSubjectsToQuestion($data['subjects'], $id);
        }
    }

    public function getRandomQuestionsBySubjects($data, $return_ids = false)
    {

        $result_ids = [];
        foreach($data as $subject)
        {
            if($result_ids)
                $this->db->where_not_in('question_id', $result_ids);

            $questions = $this->db
                ->where('subject_id', $subject['subject_id'])
                ->limit($subject['count_questions'])
                ->order_by('rand()')
                ->get('bank_questions_subjects')->result_array();

             $questions = array_column($questions, 'question_id');

            $result_ids = $result_ids ? array_merge($questions, $result_ids) : $questions;

        }

        $questions = $result_ids;

        if(!$return_ids)
        {
            $questions = $this->getQuestions($result_ids);

            foreach ($questions as &$question)
            {
                $question['answers'] = $this->getAnswersByQuestion($question['id']);
            }
        }


        return $questions;
    }

    public function assignQuestionsToStudent($user_id, $test_id, $questions)
    {
        $insert_data = [];

        foreach ($questions as $question)
        {
            $insert_data_i = [
                'user_id' => $user_id,
                'question_id' => $question,
                'test_id' => $test_id
            ];

            $insert_data[] = $insert_data_i;
        }

        $this->db->insert_batch('bank_questions_students_tests', $insert_data);
    }

    public function getStudentQuestions($user_id, $test_id)
    {

           $questions = $this->db
                                ->where('user_id', $user_id)
                                ->where('test_id', $test_id)
                                ->order_by('question_id', 'ASC')
                                ->group_by('question_id')
                                ->get('bank_questions_students_tests')
                                ->result_array();

           return array_column($questions, 'question_id');
    }


    public function transformForTest($questions)
    {

    }

    public function addQuestionsToTest($test_id, $questions)
    {

        $insert_q_a = [];
        foreach($questions as $question)
        {
            $insert_q = [
                'curs_test_id' => $test_id,
                'name' => $question['name'],
                'description' => $question['description'],
                'answers' => $question['type'],
                'image' => $question['image']
            ];

            $this->db->insert('curs_questions' , $insert_q);

            $question_id = $this->db->insert_id();


            if($question['type'] != 'long')
            {
                $insert_q_a_i = $this->buildInsertQuestionAnswers($question['answers'], $question_id);
                $insert_q_a = array_merge($insert_q_a_i, $insert_q_a);
            }
        }

        $this->db->insert_batch('curs_questions_answers', $insert_q_a);
    }


    public function addSubjectsToTest($subjects, $test_id)
    {
        $insert_data = [];

        foreach($subjects as $subject)
        {
            $insert_data_i = [
                'test_id' => $test_id,
                'subject_id' => $subject['subject'],
                'count_questions' => $subject['count']
            ];

            $insert_data[] = $insert_data_i;
        }

        $this->db->insert_batch('bank_subjects_tests', $insert_data);
    }

    public function getTestSubjects($test_id)
    {
        return $this->db
            ->where('test_id', $test_id)
            ->get('bank_subjects_tests')
            ->result_array();
    }

    private function buildInsertQuestionAnswers($answers, $question_id)
    {
        $result = [];

        foreach ($answers as $answer)
        {
            $result[] = [
                'question_id' => $question_id,
                'answer' => $answer['name'],
                'correct' => $answer['correct']
            ];
        }

        return $result;
    }

    public function createQuestions($data)
    {
        foreach ($data as $item)
        {
            $item_data = $item;
            unset($item_data['answers'], $item_data['subjects'], $item_data['correct_keys']);

            $this->db->insert('bank_questions', $item_data);

            $item_id = $this->db->insert_id();

            if($item['answers'])
            {
                $this->createAnswers($item_id, $item['answers'], $item['correct_keys']);
            }

            if($item['subjects'])
            {
                $this->assignSubjectsToQuestion($item['subjects'], $item_id);
            }
        }

    }



    public function deleteQuestions($ids)
    {
        if(!is_array($ids)) $ids = [$ids];

        $this->db
            ->where_in('id', $ids)
            ->delete('bank_questions');

        $this->db
            ->where_in('question_id', $ids)
            ->delete('bank_questions_answers');

        $this->db
            ->where_in('question_id', $ids)
            ->delete('bank_questions_subjects');

        $this->db
            ->where_in('question_id', $ids)
            ->delete('bank_questions_students_tests');
    }

    public function getSignificancePercent($userId,  $testId)
    {

        $qCount = $this->db
                        ->where('test_id', $testId)
                        ->where('user_id', $userId)
                        ->get('bank_questions_students_tests')
                        ->num_rows();



       return 100 / $qCount;
    }

    public function getSubjectsByQuestion($id)
    {
       return $this->db->select('bs.*')
                         ->where('bqs.question_id', $id)
                         ->join('bank_subjects bs', 'bqs.subject_id=bs.id')
                         ->group_by('bs.id')
                         ->get('bank_questions_subjects bqs')
                         ->result_array();
    }

    public function getAnswersByQuestion($id, $correct = false)
    {
        if($correct)
            $this->db->where('correct', 1);

       return $this->db
                    ->where('question_id', $id)
                    ->get('bank_questions_answers')
                    ->result_array();
    }

    public function getAnswers($ids = null)
    {
        if($ids)
        {
            $this->db->where_in('id', is_array($ids) ? $ids : [$ids]);
        }

        return $this->db->get('bank_questions_answers')->result_array();
    }

    public function getAnswersByQuestions($ids, $transform = false)
    {
        $result = [];

        foreach ($ids as $k => $id)
        {
            $answers = $this->getAnswersByQuestion($id);
            $result[$k] = $transform ? $this->transformAnswers($answers) : $answers;
        }


        return $result;
    }

    public function deleteAnswersByQuestion($id)
    {
        $this->db
            ->where('question_id', $id)
            ->delete('bank_questions_answers');
    }

    public function createAnswers($id, $data, $correct)
    {
        $insert_data = [];
        foreach ($data as $k => $item)
        {
            $insert_data[] =
            [
                'name' => $item,
                'question_id' => $id,
                'correct' => $correct[$k] ?? 0
            ];
        }

        $this->db->insert_batch('bank_questions_answers', $insert_data);
    }

    public function getSubjects($id = null)
    {
        if($id) $this->db->where('bs.id', $id);

        return $this->db->get('bank_subjects bs')->result_array();
    }

    public function createSubject($data)
    {
        $this->db->insert('bank_subjects', $data);
        return $this->db->insert_id();
    }

    public function updateSubject($subject_id, $data)
    {
        $this->db->where_in('id', $subject_id);
        $this->db->update('bank_subjects', $data);
    }

    public function assignSubjectsToQuestion($ids, $question_id)
    {

        $insert_data = [];

        foreach($ids as $id)
        {
            $insert_data[] =
            [
                'question_id' => $question_id,
                'subject_id' =>   $id
            ];
        }

        $this->db->insert_batch('bank_questions_subjects', $insert_data);
    }

    public function getOrCreateSubjectByName($name)
    {
        $subject = $this->db->where('name', $name)->get('bank_subjects')->row();

        if($subject)
        {
            $result = $subject->id;
        } else {
            $result = $this->createSubject(['name' => $name]);
        }

        return $result;
    }



    public function clearQuestionSubjects($id)
    {
        $this->db->where('question_id', $id)->delete('bank_questions_subjects');
    }

    public function deleteSubject($ids)
    {
        if(!is_array($ids)) $ids = [$ids];
        $this->db->where_in('id', $ids)->delete('bank_subjects');
        $this->db->where_in('subject_id', $ids)->delete('bank_questions_subjects');
    }

    public function transformAnswers($answers)
    {
        $result = [];

        foreach ($answers as $answer)
        {
            $result[] = [
                'id' => $answer['id'],
                'question_id' => $answer['question_id'],
                'answer' => $answer['name'],
                'correct' => $answer['correct'],
                'attemp' => 1
            ];
        }

        return $result;
    }

    public function transformQuestions($questions)
    {

        $result = [];

        foreach ($questions as $question)
        {
            $result[] = [
                'question_id' => $question['id'],
                'answers' => $question['type'],
                'image' => $question['image'],
                'name' => $question['name'],
                'description' => $question['description']

            ];
        }

        return $result;
    }

}