<?php
/***
 * Candy框架 Confirmation类
 * 
 * $Author: 刘森 (fingerboy@qq.com) $
 * $Date: 2022-3-5 17:20:22 $   
 */
  
declare(strict_types=1);
namespace Console\Output\question;

use \Console\Output\Question;

class Confirmation extends Question
{

    private $trueAnswerRegex;

    /**
     * 构造方法
     * @param string $question        问题
     * @param bool   $default         默认答案
     * @param string $trueAnswerRegex 验证正则
     */
    public function __construct(string $question, bool $default = true, string $trueAnswerRegex = '/^y/i')
    {
        parent::__construct($question, (bool) $default);

        $this->trueAnswerRegex = $trueAnswerRegex;
        $this->setNormalizer($this->getDefaultNormalizer());
    }

    /**
     * 获取默认的答案回调
     * @return callable
     */
    private function getDefaultNormalizer()
    {
        $default = $this->getDefault();
        $regex   = $this->trueAnswerRegex;

        return function ($answer) use ($default, $regex) {
            if (is_bool($answer)) {
                return $answer;
            }

            $answerIsTrue = (bool) preg_match($regex, $answer);
            if (false === $default) {
                return $answer && $answerIsTrue;
            }

            return !$answer || $answerIsTrue;
        };
    }
}
