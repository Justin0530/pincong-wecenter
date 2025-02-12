<?php
/*
+--------------------------------------------------------------------------
|   WeCenter [#RELEASE_VERSION#]
|   ========================================
|   by WeCenter Software
|   © 2011 - 2014 WeCenter. All Rights Reserved
|   http://www.wecenter.com
|   ========================================
|   Support: WeCenter@qq.com
|
+---------------------------------------------------------------------------
*/

define('IN_AJAX', TRUE);


if (!defined('IN_ANWSION'))
{
	die;
}

class ajax extends AWS_CONTROLLER
{
	public function setup()
	{
		HTTP::no_cache_header();
	}

	private function get_anonymous_uid($type)
	{
		if (!$anonymous_uid = $this->model('anonymous')->get_anonymous_uid())
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('本站未开启匿名功能')));
		}

		if (!$this->model('anonymous')->check_rate_limit($type, $anonymous_uid))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('今日匿名额度已经用完')));
		}

		if (!$this->model('anonymous')->check_spam($anonymous_uid))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('检测到滥用行为, 匿名功能暂时关闭')));
		}

		return $anonymous_uid;
	}

	private function validate_title_length($type, &$length)
	{
		$length_min = intval(get_setting('title_length_min'));
		$length_max = intval(get_setting('title_length_max'));
		$length = cjk_strlen($_POST['title']);
		if ($length_min AND $length < $length_min)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('标题字数不得小于 %s 字', $length_min)));
		}
		if ($length_max AND $length > $length_max)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('标题字数不得大于 %s 字', $length_max)));
		}
	}

	private function validate_body_length($type)
	{
		$length_min = intval(get_setting($type . '_body_length_min'));
		$length_max = intval(get_setting($type . '_body_length_max'));
		$length = cjk_strlen($_POST['message']);
		if ($length_min AND $length < $length_min)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('正文字数不得小于 %s 字', $length_min)));
		}
		if ($length_max AND $length > $length_max)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('正文字数不得大于 %s 字', $length_max)));
		}
	}

	private function validate_reply_length($type)
	{
		$length_min = intval(get_setting($type . '_reply_length_min'));
		$length_max = intval(get_setting($type . '_reply_length_max'));
		$length = cjk_strlen($_POST['message']);
		if ($length_min AND $length < $length_min)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('回复字数不得小于 %s 字', $length_min)));
		}
		if ($length_max AND $length > $length_max)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('回复字数不得大于 %s 字', $length_max)));
		}
	}

	private function do_validate()
	{
		if (!check_user_operation_interval('publish', $this->user_id, $this->user_info['permission']['interval_post']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('操作过于频繁, 请稍后再试')));
		}

		$_POST['later'] = intval($_POST['later']);
		if ($_POST['later'])
		{
			if (!$this->user_info['permission']['post_later'])
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不能延迟发布')));
			}

			if ($_POST['later'] < 10)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('延迟时间不能小于 10 分钟')));
			}

			if ($_POST['later'] > 1440 AND $this->user_info['group_id'] != 1 AND $this->user_info['group_id'] != 2 AND $this->user_info['group_id'] != 3)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('延迟时间不能大于 1440 分钟')));
			}
		}
	}

	private function validate_thread($act, $type)
	{
		$this->do_validate();

		if ($_POST['anonymous'] AND !$this->user_info['permission']['post_anonymously'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不能匿名')));
		}

		$_POST['title'] = trim($_POST['title']);
		if (!$_POST['title'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请输入标题')));
		}
		$this->validate_title_length($type, $title_length);

		if ($type == 'question' AND get_setting('question_ends_with_question') == 'Y')
		{
			$question_mark = cjk_substr($_POST['title'], $title_length - 1, 1);
			if ($question_mark != '？' AND $question_mark != '?' AND $question_mark != '¿')
			{
				H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('提问请以问号结尾')));
			}
		}

		if ($act == 'publish' AND !check_repeat_submission($this->user_id, $_POST['title']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请不要重复提交')));
		}

		$_POST['message'] = trim($_POST['message']);
		$this->validate_body_length($type);

		if ($_POST['topics'])
		{
			$topic_title_limit = intval(get_setting('topic_title_limit'));
			foreach ($_POST['topics'] AS $key => $topic_title)
			{
				$topic_title = trim($topic_title);

				if (!$topic_title)
				{
					unset($_POST['topics'][$key]);
				}
				else
				{
					if ($topic_title_limit AND strlen($topic_title) > $topic_title_limit AND !$this->model('topic')->get_topic_id_by_title($topic_title))
					{
						H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('话题标题字数超出限制')));
						break;
					}
					$_POST['topics'][$key] = $topic_title;
				}
			}

			if (get_setting('question_topics_limit') AND sizeof($_POST['topics']) > get_setting('question_topics_limit'))
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('单个问题话题数量最多为 %s 个, 请调整话题数量', get_setting('question_topics_limit'))));
			}
		}

		if ($act == 'modify')
		{
			return;
		}

		if (get_setting('category_enable') == 'N')
		{
			$_POST['category_id'] = 1;
		}
		else
		{
			$_POST['category_id'] = intval($_POST['category_id']);
		}

		if (!$_POST['category_id'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('请选择分类')));
		}

		if (!$this->model('category')->check_user_permission($_POST['category_id'], $this->user_info['permission']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('你的声望还不能在这个分类发言')));
		}

		if (!$this->model('category')->category_exists($_POST['category_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('分类不存在')));
		}
	}


	private function validate_reply($act, $parent_type)
	{
		$this->do_validate();

		if ($_POST['anonymous'] AND !$this->user_info['permission']['reply_anonymously'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不能匿名')));
		}

		$_POST['message'] = trim($_POST['message']);
		if (!$_POST['message'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('请输入回复内容')));
		}
		$this->validate_reply_length($parent_type);

		if ($act == 'publish' AND !check_repeat_submission($this->user_id, $_POST['message']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请不要重复提交')));
		}
	}



/*
+--------------------------------------------------------------------------
|   发布主题
+---------------------------------------------------------------------------
*/

	public function publish_question_action()
	{
		if (!$this->user_info['permission']['publish_question'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不够')));
		}

		if (!$this->model('currency')->check_balance_for_operation($this->user_info['currency'], 'currency_system_config_new_question'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的剩余%s已经不足以进行此操作', get_setting('currency_name'))));
		}

		if (!$this->model('ratelimit')->check_question($this->user_id, $this->user_info['permission']['thread_limit_per_day']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你今天发布的问题已经达到上限')));
		}

		$this->validate_thread('publish', 'question');

		if ($_POST['anonymous'])
		{
			$publish_uid = $this->get_anonymous_uid('question');
		}
		else
		{
			$publish_uid = $this->user_id;
		}

		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_repeat_submission_digest($this->user_id, $_POST['title']);
		set_user_operation_last_time('publish', $this->user_id);

		$question_id = $this->model('publish')->publish_question(array(
			'title' => $_POST['title'],
			'message' => $_POST['message'],
			'category_id' => $_POST['category_id'],
			'uid' => $publish_uid,
			'topics' => $_POST['topics'],
			'permission_create_topic' => $this->user_info['permission']['create_topic'],
			'ask_user_id' => $_POST['ask_user_id'],
			'auto_focus' => !$_POST['anonymous'],
		), $this->user_id, $_POST['later']);

		if ($_POST['later'])
		{
			// 延迟显示
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/publish/delay/')
			), 1, null));
		}

		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/question/' . $question_id)
		), 1, null));
	}


	public function publish_article_action()
	{
		if (!$this->user_info['permission']['publish_article'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不够')));
		}

		if (!$this->model('currency')->check_balance_for_operation($this->user_info['currency'], 'currency_system_config_new_article'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的剩余%s已经不足以进行此操作', get_setting('currency_name'))));
		}

		if (!$this->model('ratelimit')->check_article($this->user_id, $this->user_info['permission']['thread_limit_per_day']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你今天发布的文章已经达到上限')));
		}

		$this->validate_thread('publish', 'article');

		if ($_POST['anonymous'])
		{
			$publish_uid = $this->get_anonymous_uid('article');
		}
		else
		{
			$publish_uid = $this->user_id;
		}

		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_repeat_submission_digest($this->user_id, $_POST['title']);
		set_user_operation_last_time('publish', $this->user_id);

		$article_id = $this->model('publish')->publish_article(array(
			'title' => $_POST['title'],
			'message' => $_POST['message'],
			'category_id' => $_POST['category_id'],
			'uid' => $publish_uid,
			'topics' => $_POST['topics'],
			'permission_create_topic' => $this->user_info['permission']['create_topic'],
		), $this->user_id, $_POST['later']);

		if ($_POST['later'])
		{
			// 延迟显示
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/publish/delay/')
			), 1, null));
		}

		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/article/' . $article_id)
		), 1, null));
	}


	public function publish_video_action()
	{
		if (!$this->user_info['permission']['publish_video'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不够')));
		}

		if (!$web_url = trim($_POST['web_url']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('请输入影片来源')));
		}

		$metadata = Services_VideoParser::parse_video_url($web_url);
		if (!$metadata)
		{
			H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('无法识别影片来源')));
		}

		if (!$this->model('currency')->check_balance_for_operation($this->user_info['currency'], 'currency_system_config_new_video'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的剩余%s已经不足以进行此操作', get_setting('currency_name'))));
		}

		if (!$this->model('ratelimit')->check_video($this->user_id, $this->user_info['permission']['thread_limit_per_day']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你今天发布的影片已经达到上限')));
		}

		$this->validate_thread('publish', 'video');

		if ($_POST['anonymous'])
		{
			$publish_uid = $this->get_anonymous_uid('video');
		}
		else
		{
			$publish_uid = $this->user_id;
		}

		// TODO: why?
		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_repeat_submission_digest($this->user_id, $_POST['title']);
		set_user_operation_last_time('publish', $this->user_id);

		$video_id = $this->model('publish')->publish_video(array(
			'title' => $_POST['title'],
			'message' => $_POST['message'],
			'category_id' => $_POST['category_id'],
			'uid' => $publish_uid,
			'topics' => $_POST['topics'],
			'permission_create_topic' => $this->user_info['permission']['create_topic'],
			'source_type' => $metadata['source_type'],
			'source' => $metadata['source'],
			'duration' => 0,
		), $this->user_id, $_POST['later']);

		if ($_POST['later'])
		{
			// 延迟显示
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/publish/delay/')
			), 1, null));
		}

		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/video/' . $video_id)
		), 1, null));
	}


	public function modify_question_action()
	{
		if (!check_user_operation_interval('publish', $this->user_id, $this->user_info['permission']['interval_modify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('操作过于频繁, 请稍后再试')));
		}

		if (!$question_info = $this->model('question')->get_question_info_by_id($_POST['question_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}

		if ($question_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题已锁定, 不能编辑')));
		}

		if (!$this->user_info['permission']['edit_any_post'])
		{
			if ($question_info['uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个问题')));
			}
		}

		if (!$_POST['do_delete'])
		{
			$this->validate_thread('modify', 'question');
		}

		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_user_operation_last_time('publish', $this->user_id);

		if ($_POST['do_delete'])
		{
			$this->model('question')->clear_question(
				$question_info['question_id'],
				$this->user_id
			);
		}
		else
		{
			$this->model('question')->modify_question(
				$question_info['question_id'],
				$this->user_id,
				$_POST['title'],
				$_POST['message']
			);
		}

		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/question/' . $question_info['question_id'])
		), 1, null));

	}


	public function modify_article_action()
	{
		if (!check_user_operation_interval('publish', $this->user_id, $this->user_info['permission']['interval_modify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('操作过于频繁, 请稍后再试')));
		}

		if (!$article_info = $this->model('article')->get_article_info_by_id($_POST['article_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('文章不存在')));
		}

		if ($article_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('文章已锁定, 不能编辑')));
		}

		if (!$this->user_info['permission']['edit_any_post'])
		{
			if ($article_info['uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个文章')));
			}
		}

		if (!$_POST['do_delete'])
		{
			$this->validate_thread('modify', 'article');
		}

		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_user_operation_last_time('publish', $this->user_id);

		if ($_POST['do_delete'])
		{
			$this->model('article')->clear_article(
				$article_info['id'],
				$this->user_id
			);
		}
		else
		{
			$this->model('article')->modify_article(
				$article_info['id'],
				$this->user_id,
				$_POST['title'],
				$_POST['message']
			);
		}

		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/article/' . $article_info['id'])
		), 1, null));
	}


	public function modify_video_action()
	{
		if (!check_user_operation_interval('publish', $this->user_id, $this->user_info['permission']['interval_modify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('操作过于频繁, 请稍后再试')));
		}

		if (!$video_info = $this->model('video')->get_video_info_by_id($_POST['video_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('影片不存在')));
		}

		if ($video_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('影片已锁定, 不能编辑')));
		}

		if (!$this->user_info['permission']['edit_any_post'])
		{
			if ($video_info['uid'] != $this->user_id)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你没有权限编辑这个影片')));
			}
		}

		if (!$_POST['do_delete'])
		{
			if ($web_url = trim($_POST['web_url']))
			{
				$metadata = Services_VideoParser::parse_video_url($web_url);
				if (!$metadata)
				{
					H::ajax_json_output(AWS_APP::RSM(null, - 1, AWS_APP::lang()->_t('无法识别影片来源')));
				}
			}

			$this->validate_thread('modify', 'video');
		}

		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_user_operation_last_time('publish', $this->user_id);

		if ($_POST['do_delete'])
		{
			$this->model('video')->clear_video(
				$video_info['id'],
				$this->user_id
			);
		}
		else
		{
			if ($metadata)
			{
				$this->model('video')->update_video_source(
					$video_info['id'],
					$metadata['source_type'],
					$metadata['source'],
					0
				);
			}

			$this->model('video')->modify_video(
				$video_info['id'],
				$this->user_id,
				$_POST['title'],
				$_POST['message']
			);
		}

		H::ajax_json_output(AWS_APP::RSM(array(
			'url' => get_js_url('/video/' . $video_info['id'])
		), 1, null));

	}



/*
+--------------------------------------------------------------------------
|   发布回应
+---------------------------------------------------------------------------
*/

	public function publish_answer_action()
	{
		if (!$this->user_info['permission']['answer_question'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不够')));
		}

		if (!$this->model('currency')->check_balance_for_operation($this->user_info['currency'], 'currency_system_config_reply_question'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的剩余%s已经不足以进行此操作', get_setting('currency_name'))));
		}

		if (!$this->model('ratelimit')->check_answer($this->user_id, $this->user_info['permission']['reply_limit_per_day']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你今天的回复已经达到上限')));
		}

		$this->validate_reply('publish', 'question');

		if (!$question_info = $this->model('question')->get_question_info_by_id($_POST['question_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}

		if ($question_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的问题不能回复')));
		}

		if (!$question_info['question_content'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经删除的问题不能回复')));
		}

		if (!$this->model('category')->check_user_permission($question_info['category_id'], $this->user_info['permission']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('你的声望还不能在这个分类发言')));
		}

		// 判断是否是问题发起者
		if (get_setting('answer_self_question') == 'N' AND $question_info['uid'] == $this->user_id)
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('不能回复自己发布的问题，你可以修改问题内容')));
		}

		// 判断是否已回复过问题
		if ((get_setting('answer_unique') == 'Y'))
		{
			if ($this->model('answer')->has_answer_by_uid($question_info['question_id'], $this->user_id))
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('一个问题只能回复一次，你可以编辑回复过的回复')));
			}
			$schedule = $this->model('answer')->fetch_one('scheduled_posts', 'id', "type = 'answer' AND parent_id = " . intval($question_info['question_id']) . " AND uid = " . intval($this->user_id));
			if ($schedule)
			{
				H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你已经使用延迟显示功能回复过该问题')));
			}
		}

		if ($_POST['anonymous'])
		{
			$publish_uid = $this->get_anonymous_uid('answer');
			$auto_focus = false;
		}
		else
		{
			$publish_uid = $this->user_id;
			$auto_focus = $_POST['auto_focus'];
		}

		// !注: 来路检测后面不能再放报错提示
		if (! valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_repeat_submission_digest($this->user_id, $_POST['message']);
		set_user_operation_last_time('publish', $this->user_id);

		$answer_id = $this->model('publish')->publish_answer(array(
			'parent_id' => $question_info['question_id'],
			'message' => $_POST['message'],
			'uid' => $publish_uid,
			'auto_focus' => $auto_focus,
			'permission_affect_currency' => $this->user_info['permission']['affect_currency'],
		), $this->user_id, $_POST['later']);

		if ($_POST['later'])
		{
			// 延迟显示
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/publish/delay/')
			), 1, null));
		}

		$answer_info = $this->model('answer')->get_answer_by_id($answer_id);
		$answer_info['user_info'] = $this->model('account')->get_user_info_by_uid($publish_uid);
		$answer_info['answer_content'] = $this->model('mention')->parse_at_user($answer_info['answer_content']);
		TPL::assign('answer_info', $answer_info);
		H::ajax_json_output(AWS_APP::RSM(array(
			'ajax_html' => TPL::process('question/ajax/answer')
		), 1, null));
	}


	public function publish_article_comment_action()
	{
		if (!$this->user_info['permission']['comment_article'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不够')));
		}

		if (!$this->model('currency')->check_balance_for_operation($this->user_info['currency'], 'currency_system_config_reply_article'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的剩余%s已经不足以进行此操作', get_setting('currency_name'))));
		}

		if (!$this->model('ratelimit')->check_article_comment($this->user_id, $this->user_info['permission']['reply_limit_per_day']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你今天的文章评论已经达到上限')));
		}

		$this->validate_reply('publish', 'article');

		if (!$article_info = $this->model('article')->get_article_info_by_id($_POST['article_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('指定文章不存在')));
		}

		if ($article_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的文章不能回复')));
		}

		if (!$article_info['title'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经删除的文章不能回复')));
		}

		if (!$this->model('category')->check_user_permission($article_info['category_id'], $this->user_info['permission']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('你的声望还不能在这个分类发言')));
		}

		if ($_POST['anonymous'])
		{
			$publish_uid = $this->get_anonymous_uid('question');
		}
		else
		{
			$publish_uid = $this->user_id;
		}

		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_repeat_submission_digest($this->user_id, $_POST['message']);
		set_user_operation_last_time('publish', $this->user_id);

		$comment_id = $this->model('publish')->publish_article_comment(array(
			'parent_id' => $article_info['id'],
			'message' => $_POST['message'],
			'uid' => $publish_uid,
			'at_uid' => $_POST['at_uid'],
			'permission_affect_currency' => $this->user_info['permission']['affect_currency'],
		), $this->user_id, $_POST['later']);

		if ($_POST['later'])
		{
			// 延迟显示
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/publish/delay/')
			), 1, null));
		}

		$comment_info = $this->model('article')->get_comment_by_id($comment_id);
		$comment_info['message'] = $this->model('mention')->parse_at_user($comment_info['message']);
		TPL::assign('comment_info', $comment_info);
		H::ajax_json_output(AWS_APP::RSM(array(
			'ajax_html' => TPL::process('article/ajax/comment')
		), 1, null));
	}


	public function publish_video_comment_action()
	{
		if (!$this->user_info['permission']['comment_video'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的声望还不够')));
		}

		if (!$this->model('currency')->check_balance_for_operation($this->user_info['currency'], 'currency_system_config_reply_video'))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你的剩余%s已经不足以进行此操作', get_setting('currency_name'))));
		}

		if (!$this->model('ratelimit')->check_video_comment($this->user_id, $this->user_info['permission']['reply_limit_per_day']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('你今天的影片评论已经达到上限')));
		}

		$this->validate_reply('publish', 'video');

		if (!$video_info = $this->model('video')->get_video_info_by_id($_POST['video_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('指定影片不存在')));
		}

		if ($video_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的影片不能回复')));
		}

		if (!$video_info['title'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经删除的影片不能回复')));
		}

		if (!$this->model('category')->check_user_permission($video_info['category_id'], $this->user_info['permission']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('你的声望还不能在这个分类发言')));
		}

		if ($_POST['anonymous'])
		{
			$publish_uid = $this->get_anonymous_uid('video_comment');
		}
		else
		{
			$publish_uid = $this->user_id;
		}

		// !注: 来路检测后面不能再放报错提示
		if (!valid_post_hash($_POST['post_hash']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('页面停留时间过长,或内容已提交,请刷新页面')));
		}

		set_repeat_submission_digest($this->user_id, $_POST['message']);
		set_user_operation_last_time('publish', $this->user_id);

		$comment_id = $this->model('publish')->publish_video_comment(array(
			'parent_id' => $video_info['id'],
			'message' => $_POST['message'],
			'uid' => $publish_uid,
			'at_uid' => $_POST['at_uid'],
			'permission_affect_currency' => $this->user_info['permission']['affect_currency'],
		), $this->user_id, $_POST['later']);

		if ($_POST['later'])
		{
			// 延迟显示
			H::ajax_json_output(AWS_APP::RSM(array(
				'url' => get_js_url('/publish/delay/')
			), 1, null));
		}

		$comment_info = $this->model('video')->get_comment_by_id($comment_id);
		$comment_info['message'] = $this->model('mention')->parse_at_user($comment_info['message']);
		TPL::assign('comment_info', $comment_info);
		H::ajax_json_output(AWS_APP::RSM(array(
			'ajax_html' => TPL::process('video/ajax/comment')
		), 1, null));
	}


	public function modify_answer_action()
	{
		if (!check_user_operation_interval('publish', $this->user_id, $this->user_info['permission']['interval_modify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('操作过于频繁, 请稍后再试')));
		}

		if (!$answer_info = $this->model('answer')->get_answer_by_id($_GET['id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('内容不存在')));
		}

		if ($answer_info['uid'] != $this->user_id and ! $this->user_info['permission']['edit_any_post'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('你没有权限进行此操作')));
		}

		if (!$question_info = $this->model('question')->get_question_info_by_id($answer_info['question_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('问题不存在')));
		}

		if ($question_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的问题不能编辑')));
		}

		if (!$_POST['do_delete'])
		{
			$this->validate_reply('modify', 'question');
		}

		set_user_operation_last_time('publish', $this->user_id);

		if ($_POST['do_delete'])
		{
			$this->model('question')->clear_answer(
				$answer_info['answer_id'],
				$this->user_id
			);
		}
		else
		{
			$this->model('question')->modify_answer(
				$answer_info['answer_id'],
				$this->user_id,
				$_POST['message']
			);
		}

		// 删除回复邀请, 如果有
		$this->model('question')->answer_question_invite($answer_info['question_id'], $this->user_id);

		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}


	public function modify_article_comment_action()
	{
		if (!check_user_operation_interval('publish', $this->user_id, $this->user_info['permission']['interval_modify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('操作过于频繁, 请稍后再试')));
		}

		if (!$comment_info = $this->model('article')->get_comment_by_id($_GET['id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('内容不存在')));
		}

		if ($comment_info['uid'] != $this->user_id and ! $this->user_info['permission']['edit_any_post'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('你没有权限进行此操作')));
		}

		if (!$article_info = $this->model('article')->get_article_info_by_id($comment_info['article_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('文章不存在')));
		}

		if ($article_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的文章不能编辑')));
		}

		if (!$_POST['do_delete'])
		{
			$this->validate_reply('modify', 'article');
		}

		set_user_operation_last_time('publish', $this->user_id);

		if ($_POST['do_delete'])
		{
			$this->model('article')->clear_article_comment(
				$comment_info['id'],
				$this->user_id
			);
		}
		else
		{
			$this->model('article')->modify_article_comment(
				$comment_info['id'],
				$this->user_id,
				$_POST['message']
			);
		}

		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}

	public function modify_video_comment_action()
	{
		if (!check_user_operation_interval('publish', $this->user_id, $this->user_info['permission']['interval_modify']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('操作过于频繁, 请稍后再试')));
		}

		if (!$comment_info = $this->model('video')->get_comment_by_id($_GET['id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('内容不存在')));
		}

		if ($comment_info['uid'] != $this->user_id and ! $this->user_info['permission']['edit_any_post'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, -1, AWS_APP::lang()->_t('你没有权限进行此操作')));
		}

		if (!$video_info = $this->model('video')->get_video_info_by_id($comment_info['video_id']))
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('影片不存在')));
		}

		if ($video_info['lock'])
		{
			H::ajax_json_output(AWS_APP::RSM(null, '-1', AWS_APP::lang()->_t('已经锁定的影片不能编辑')));
		}

		if (!$_POST['do_delete'])
		{
			$this->validate_reply('modify', 'video');
		}

		set_user_operation_last_time('publish', $this->user_id);

		if ($_POST['do_delete'])
		{
			$this->model('video')->clear_video_comment(
				$comment_info['id'],
				$this->user_id
			);
		}
		else
		{
			$this->model('video')->modify_video_comment(
				$comment_info['id'],
				$this->user_id,
				$_POST['message']
			);
		}

		H::ajax_json_output(AWS_APP::RSM(null, 1, null));
	}

}
