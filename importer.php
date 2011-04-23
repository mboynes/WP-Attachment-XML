<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN"
        "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
	<meta http-equiv="content-type" content="text/html; charset=utf-8" />
	<title>PHP Image Resize Test Page</title>
	<link rel="stylesheet" href="global.css" />
</head>
<body>
<div id="wrapper">
	<form enctype="multipart/form-data" method="post">
	    <input type="hidden" name="MAX_FILE_SIZE" value="1000000" />
		<p>
		<label for="export_file">Upload your WordPress Export XML file here:</label>
		<input type="file" name="export_file" id="export_file" />
		</p>
		<p>
		<label for="domain">Domain:</label>
		<input type="text" name="domain" value="" id="domain" />
		</p>
		<p>
		<label for="post_types">Post Types (comma-separated, no spaces)</label>
		<input type="text" name="post_types" value="post,page" id="post_types" />
		</p>
		<p><input type="submit" /></p>
	</form>

<?php

function get_tag_contents($tag, &$contents) {
	if (preg_match('`<'.$tag.'>(.*?)</'.$tag.'>`si', $contents, $match)) {
		return $match[1];
	}
	return false;
}

function get_tag_attr($tag, $attr, &$contents) {
	if (preg_match('`[<\[]'.$tag.'\s[^>\]]*'.$attr.'\s*=\s*[\'"](.*?)[\'"]`si', $contents, $match)) {
		return $match[1];
	}
	return false;
}

function xml_item_template($arr) {
	$return = "\n\t<item>";
	foreach ($arr as $key => $val) {
		if ($val === false) continue;
		$attr = '';
		if (is_array($val)) {
			$content = $val['content'];
			unset($val['content']);
			foreach ($val as $k => $v)
				$attr .= ' '.$k.'="'.$v.'"';
			$val = $content;
		}
		$return .= "\n\t\t<".$key.$attr.'>'.$val.'</'.$key.'>';
	}
	return $return . "\n\t</item>\n";
}


if (strtolower($_SERVER['REQUEST_METHOD']) == 'post') :
	# 0. Take in file, domain, allowed post types

	$allowed_post_types = explode(',', $_POST['post_types']);

	$filename = $_FILES['export_file']['tmp_name'];
	$handle = fopen($filename, "r");
	$html = fread($handle, filesize($filename));
	fclose($handle);
	unlink($filename);

	$domain = $_POST['domain'];

	$messages = array();
	$output = '';


	# 1. Locate posts
	$has_posts = preg_match_all('`<item>.*?</item>`si', $html, $posts);
	if ($has_posts && is_array($posts) && isset($posts[0]) && is_array($posts[0]) && !empty($posts[0])) {
		// print_r($posts);

		foreach ($posts[0] as $post) {
			# 1.1 Get post type
			$post_type = get_tag_contents('wp:post_type', $post);
			if (!$post_type || !in_array(trim($post_type), $allowed_post_types)) { $messages[] = 'Skipping post because it has no post type or post type is allowed ['.$post_type.']'; continue; }

			# 1.2 Get post ID
			$post_id = get_tag_contents('wp:post_id', $post);
			if (!$post_id) { $messages[] = 'Skipping post b/c it has no ID'; continue; }

			# 2. Locate images (get image URLs)
			$images = $complete = array();
			$has_images = preg_match_all('/(?:\[caption[^\]]*?\]\s*)?(?:<a\s[^>]+?>\s*)?<img\s.*?src\s*=\s*["\'](.*?)[\'"][^>]*?>(?:\s*<\/a>)?(?:\s*\[\/caption\])?/si', $post, $images);
			if ($has_images && is_array($images) && isset($images[1]) && is_array($images[1]) && !empty($images[1])) {
				// print_r($images);
				for ($i = 0; $i < count($images[1]); $i++) {
					$image = $images[1][$i];
				
					# Only process images for the given domain
					if (strpos($image,'http') === 0 && strpos($image,$domain) === false) { $messages[] = 'Skipping image because it is not on '.$domain.' ['.$image.']'; continue; }
					// echo "\n\nimage $i: $image\n\n";
					$contents = $images[0][$i];
					// echo "contents $i: $contents\n\n";
					# avoid dupes
					if (!array_search($image, $complete)) {
						$complete[] = $image;
						/*
							Initially had this to work off the link, which was risky and prone to some obvious bugs,
							but the biggest issue is that the image path formatting would have to be exactly like WP.
							Now, it works only off of the img src, which means it doesn't download large images.
							Eventually, this should be set to see if the image is the same, then remap to the blog's
							image sizes.
						*/
						// $guid = get_tag_attr('a', 'href', $contents);
						// if (!$guid || !preg_match('/(?:jpg|gif|png)$/i',$guid))
						$guid = $image;

						$title = get_tag_attr('img', 'title', $contents);
						if (!$title) $title = urldecode(urldecode(basename($image)));

						# build out the XML
						$output .= xml_item_template(array(
							'title' => $title,
							// 'link' => get_tag_attr('a', 'href', $contents), # Leave this out for now
							'pubDate' => get_tag_contents('pubDate', $post),
							'dc:creator' => get_tag_contents('dc:creator', $post),
							'guid' => array(
								'isPermaLink' => "false",
								'content' => $guid
							),
							// 'description' => '',
							// 'content:encoded' => '',
							'excerpt:encoded' => '<![CDATA[' . get_tag_attr('caption','caption', $contents) . ']]>',
							// 'wp:post_id' => '',
							'wp:post_date' => get_tag_contents('wp:post_date', $post),
							'wp:post_date_gmt' => get_tag_contents('wp:post_date_gmt', $post),
							'wp:comment_status' => get_tag_contents('wp:comment_status', $post),
							'wp:ping_status' => get_tag_contents('wp:ping_status', $post),
							'wp:post_name' => $title,
							'wp:status' => 'inherit',
							'wp:post_parent' => $post_id,
							'wp:menu_order' => '0',
							'wp:post_type' => 'attachment',
							'wp:post_password' => get_tag_contents('wp:post_password', $post),
							'wp:is_sticky' => '0',
							'wp:attachment_url' => $guid
						));
					}
				}
			}
		
		}

	}

	echo '<div class="success">Import complete!',
		(count($messages) ? 'Useful messages: <ul><li>' . implode("</li><li>", $messages) . "</li></ul>" : ''),
		'<p>Add this to your file prior to importing, before the ',htmlentities('</channel>'),' tag:</p></div>';
	echo '<div><textarea rows="20" cols="100">', htmlentities($output), '</textarea></div>';

endif;
?>



</div>
</body>
</html>
