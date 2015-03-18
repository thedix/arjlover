<?php
	$MAX_PART_SIZE = 16 * (1 << 30);
	$HOST = 'multiki.arjlover.net';
	$HTML_FILE = 'in/multiki.html';
	$RE_PARSE_EXISTING = '#^(?:\S+\s+){4}(\d+)\s+(?:\S+\s+){3}(.+)$#m';
	$RE_PARSE_HTML = '#<tr class=[eo]>.+?<td class=l><a href="[^"]+"[^>]+>([^<]+)</a></td>\s+<td class=r>([^<]+)</td>.+?<td><a href="([^"]+)"[^>]+>http</a>#sm';
	$ALPHABET = array('А', 'Б', 'В', 'Г', 'Д', 'Е', 'Ё', 'Ж', 'З', 'И', 'Й', 'К', 'Л', 'М', 'Н', 'О', 'П', 'Р', 'С', 'Т', 'У', 'Ф', 'Х', 'Ц', 'Ч', 'Ш', 'Щ', 'Ъ', 'Ы', 'Ь', 'Э', 'Ю', 'Я');


	function write_part($num, $items, $to) {
		global $HOST;
		static $from = 0;

		if ($from >= $to)
			return;

		$fname = sprintf("out/download%02d.url", $num);
		$fd = @fopen($fname, 'w');
		if (!$fd)
			die("Error creating file $fname");

		for ($i=$from; $i<$to; ++$i) {
			fwrite($fd, "http://{$HOST}{$items[$i]['url']}\n");
		}

		fclose($fd);

		$from = $to;
	}


	if (extension_loaded('mbstring'))
		mb_internal_encoding("UTF-8");

	// существующие файлы
	$existing = array();
	$s = file_get_contents('in/existing.lst');
	if (!empty($s)) {
		if (!preg_match_all($RE_PARSE_EXISTING, $s, $m, PREG_SET_ORDER))
			die('Error parsing existing');

		foreach ($m as $item) {
			$existing[trim($item[2])] = floatval($item[1]);
		}
	}

	// читаем и парсим файл
	$s = file_get_contents($HTML_FILE);
	if (empty($s))
		die("Error opening $HTML_FILE");

	if (!preg_match_all($RE_PARSE_HTML, $s, $m, PREG_SET_ORDER))
		die('Error parsing HTML');

	// открываем файлы скриптов
	$s_init = "#!/bin/sh\n\n";
	$fds = array();
	foreach (array('rename_flat', 'rename_abc', 'hardlink_flat', 'hardlink_abc', 'delete') as $name) {
		$fds[$name] = fopen("out/$name.sh", 'w');
		if (!$fds[$name])
			die("Error creating file $name.sh");

		fwrite($fds[$name], $s_init);
	}

	$total_size    = 0; // размер всего архива
	$download_size = 0; // размер, который надо скачать/перекачать
	$part_size     = 0; // размер части
	$part_num      = 1; // номер части
	$i             = 0; // текущая позиция
	$abc           = array(); // использованные буквы алфавита

	$download = array(); // тут хранится список для выкачивания
	// собираем данные о каждом фильме
	foreach ($m as $item) {
		$title = iconv('windows-1251', 'utf-8', $item[1]);
		// замены запрещённых символов
		$title = preg_replace('#(\d+)/(\d+)\s*\)#', '$1 из $2)', $title);
		$title = preg_replace('#^(\d+)/(\d+)$#', '$1 на $2', $title);
		$title = preg_replace('#\s+/\s+#', '. ', $title);
		$title = preg_replace('#^«(.+)»$#', '$1', $title);
		$title = str_replace(array('/', ':', '?'), array(' ', '.', ''), $title);
		$title = str_replace(array('"', '«', '»'), "'", $title);

		$size = floatval(str_replace('.', '', $item[2]));
		$url  = $item[3];

		$pi = pathinfo($url);
		if (!$pi)
			die("Can not get path info for {$url}");

		$item = array (
			'title' => $title,
			'size'  => $size,
			'url'   => $url,
			'fname' => $pi['basename'],
			'ext'   => $pi['extension'],
		);

		// получаем первую букву
		// если есть возможность, используем mbstring
		$letter = extension_loaded('mbstring') ? mb_strtoupper(mb_substr($item['title'], 0, 1))
		                                       : substr($item['title'], 0, 2);
		if ($letter == 'Ё')
			$letter = 'Е';
		if (!in_array($letter, $ALPHABET))
			$letter = '#';

		// если директория с буквой еще не создавалась, то создать ее
		if (!isset($abc[$letter])) {
			$s = "mkdir \"$2/$letter\"\n";
			fwrite($fds['hardlink_abc'], $s);
			fwrite($fds['rename_abc'],   $s);
			$abc[$letter] = true;
		}

		$total_size += $size;

		fwrite($fds['hardlink_flat'], "ln \"$1/{$item['fname']}\" \"$2/{$item['title']}.{$item['ext']}\"\n");
		fwrite($fds['hardlink_abc'],  "ln \"$1/{$item['fname']}\" \"$2/$letter/{$item['title']}.{$item['ext']}\"\n");
		fwrite($fds['rename_flat'],   "mv \"$1/{$item['fname']}\" \"$2/{$item['title']}.{$item['ext']}\"\n");
		fwrite($fds['rename_abc'],    "mv \"$1/{$item['fname']}\" \"$2/$letter/{$item['title']}.{$item['ext']}\"\n");

		// пропускаем, если есть такой файл и размеры совпадают
		if (isset($existing[$item['fname']])) {
			$old_size = $existing[$item['fname']];
			unset($existing[$item['fname']]);

			if ($old_size == $item['size'])
				continue;

			echo "Existing {$item['fname']} with different sizes: old $old_size, new {$item['size']}\n";
		}

		// такого файла нет или размеры не совпадают - перекачиваем
		$download[$i++] = $item;
		$download_size += $size;

		$part_size += $size;
		if ($part_size >= $MAX_PART_SIZE) {
			write_part($part_num++, $download, $i);
			$part_size = 0;
		}
	}

	// пишем остаток
	write_part($part_num, $download, $i);

	// оставшиеся в $existing файлы надо удалить
	foreach ($existing as $fname=>$v)
		fwrite($fds['delete'], "rm \"$1/$fname\"\n");

	// закрываем файлы
	foreach ($fds as $name=>$fd)
		fclose($fd);

	echo "Total size: $total_size\n";
	echo "Download size: $download_size\n";
