<meta charset="UTF-8" /> <!-- Смена кодировки страницы на UTF-8 -->

<?php

	set_time_limit(0); // Т.к. скрипт может выполняться более установленнх по умолчанию 180 сек. - отключим таймер
	
	$connection = mysqli_connect('localhost', 'root' , '', 'parse'); // Соединение с БД parse
	if ($connection == false) echo mysqli_connect_error(); // Вывод ошибки подключения, если оно не удалось
	mysqli_query($connection, "DELETE FROM procedures"); // Очистка таблиц перед началом работы
	mysqli_query($connection, "DELETE FROM documents");

	$number_of_sheets = Sheets ();
	$procedure_numbers = array ();
	$procedure_numbers = Procedures ($number_of_sheets);
	Main ($procedure_numbers);
	PrintData ($procedure_numbers, $number_of_sheets);

	mysqli_close($connection); // Закрыть соединение с БД после выполнения скрипта

	function Sheets () { // Функция подсчета количества страниц
		$html = file_get_contents('https://eltox.ru/registry/procedure?id=&procedure=&oos_id=&company=&inn=&type=1&price_from=&price_to=&published_from=&published_to=&offer_from=&offer_to=&status='); // Страница задаия с установленным фильтром
		$regexp = '~[0-9]+</a></li>~u';
		$matches = array();
		$count = preg_match_all($regexp, $html, $matches); // В count записывается количество совпадений. в массив matches совпадения
		if ($count != 0)
			return $matches[0][$count-1]; // Возвращаем последнее совпадение - номер последней страницы
		else
			return 0;
	}

	function Procedures ($number_of_sheets) { // Функция записи выданных фильтром номеров процедур в массив и создание соответствующих полей в БД
		global $connection;
		$numbers = array ();
		for ($sheet = 1; $sheet <= $number_of_sheets; $sheet++) { // Цикл для перебора каждой страницы с процедурами
			$html = file_get_contents("https://eltox.ru/registry/procedure/page/$sheet?id=&procedure=&oos_id=&company=&inn=&type=1&price_from=&price_to=&published_from=&published_to=&offer_from=&offer_to=&status="); // В ссылке присутствует переменная sheet для перехода на соответствующую страницу
			$regexp = '~№ [0-9]+<~u';
			$matches = array();
			$count = preg_match_all($regexp, $html, $matches); // В count записываем количество номеров процедур, найденных на странице (может отличаться, например, на последней странице)
			$regexp = '~[0-9]+~u';
			$match = array ();
			for ($proc_sheet_count = 0; $proc_sheet_count < $count; $proc_sheet_count++) // Цикл по количеству номеров процедур на странице
			{
				preg_match($regexp, $matches[0][$proc_sheet_count], $match); // Обрезаем лишнее, и записываем в массив match
				array_push($numbers, $match[0]); // Помещаем результат в массив номеров процедур
				mysqli_query($connection, "INSERT INTO procedures (num) VALUES ('$match[0]')"); // И создаем соответствующее после в таблице procedures
			}
		}
		return $numbers; // Возвращаем массив номеров процедур
	}

	function Main ($numbers) { // Функция парсинга страницы процедуры
		global $connection;

		$numbers_count = count ($numbers); // Количество процедур

		$match = array (); // Массив для записи совпадений по рег. выражениям
		$match_dir = array (); // Аналогичный  массив для записи кодированной части имени документа
		$match_path = array (); // Аналогичный массив для записи пути к документу

		$match2 = array (); // Вспомогательные массивы
		$match_dir2 = array ();
		$match_path2 = array ();

		for ($proc = 0; $proc < $numbers_count; $proc++) { // Цикл по количеству процедур

			$html = file_get_contents("https://eltox.ru/procedure/read/$numbers[$proc]"); // Страницы процедуры
			
			mysqli_query($connection, "UPDATE procedures SET link='https://eltox.ru/procedure/read/$numbers[$proc]' WHERE num='$numbers[$proc]'"); // Записываем в БД ссылку на страницу

			$regexp = '~[0-9]{11}</s~u';
			preg_match($regexp, $html, $match); // Ищем номер ООС
			$regexp = '~[0-9]{11}~';
			preg_match($regexp, $match[0], $match); // Обрезаем лишнее, оставляем только 11 цифр
			mysqli_query($connection, "UPDATE procedures SET oos='$match[0]' WHERE num='$numbers[$proc]'"); // Записываем номер ООС в БД

			$regexp = '~>[-a-z0-9._]+@[a-z]+.[a-z]+~i';
			preg_match($regexp, $html, $match); // Ищем адрес эл. почты
			$match[0] = substr($match[0], 1); // Обрезаем лишний последний символ
			mysqli_query($connection, "UPDATE procedures SET email='$match[0]' WHERE num='$numbers[$proc]'"); // Записываем адрес эл. почты в БД

			$regexp = '~"name":[^:]+~'; // Рег. выражение для поиска имени документа
			$regexp_dir = '~"name[^u]+~'; // Рег. выражение для поиска закодированной части имени документа
			$regexp_path = '~"path[^,]+~'; // Рег. выражение для поиска пути к документу

			$docs_count = preg_match_all($regexp, $html, $match); // Запись в count Количества документов процедуры, а в массив match названий документов
			preg_match_all($regexp_dir, $html, $match_dir); // Запись в массив match_dir закодированной части имен документов
			preg_match_all($regexp_path, $html, $match_path); // Запись в массив match_path пути к документам

			mysqli_query($connection, "UPDATE procedures SET docs_count='$docs_count' WHERE num='$numbers[$proc]'"); // Запись в БД количества документов процедуры (вспомогательное поле)

			if ($docs_count >> 0) // Проверка количества документов процедуры
				for ($doc = 1; $doc <= $docs_count; $doc++ ) { // Цикл по количеству документов
					mysqli_query($connection, "INSERT INTO documents (proc_num) VALUES ('$numbers[$proc]')"); // Запись в таблицу documents БД номера процедуры, к которой относится документ

					$match2 = substr($match[0][$doc-1], 22, strlen($match[0][$doc-1])-31); // Обрезаем лишнее от названия документа
					$match2 = decode($match2); // Декодируем название документа из Unicode в UTF-8
					$match_dir2 = substr($match_dir[0][$doc-1], 8, strlen($match_dir[0][$doc-1])-9); // Обрезаем лишнее от закодированной части имени документа
					$match_path2 = substr($match_path[0][$doc-1], 8, strlen($match_path[0][$doc-1])-9); // Обрезаем лишнее от пути к документу

					$link = 'http://storage.eltox.ru/' . $match_path2 . '/' . $match_dir2 . $match2; // Формируем ссылку к документу: домен + закодированная часть имени документа + пуить к документу + имя документа

					mysqli_query($connection, "UPDATE documents SET name='$match2', link='$link' WHERE (proc_num='$numbers[$proc]' AND num=0)"); // Запись ссылки на документ в БД
					mysqli_query($connection, "UPDATE documents SET num='$doc' WHERE (proc_num='$numbers[$proc]' AND num=0)"); // Запись номера документа для конктерной процедуры
				}
		}
	}

	function PrintData ($numbers, $sheets) { // Функция вывода результата на экран
		global $connection;
		$numbers_count = count ($numbers); // Количество процедур
		echo 'Парсер страницы https://eltox.ru/registry/procedure/ с установленным фильтром "Тип процедуры - Запрос цен (котировок)".<br><b>Страниц: </b><i>' . $sheets . '</i>. <b>Элементов: </b><i>' . $numbers_count . '</i>.<br><br>';
		for ($proc = 0; $proc < $numbers_count; $proc++) { // Цикл по числу процедур
			$result = mysqli_query($connection, "SELECT * FROM procedures WHERE num='$numbers[$proc]'"); // Получаем строку из БД с номером процедуры
			$myrow = mysqli_fetch_array($result); // Записываем строку в массив
			echo '<b>Номер извещения: </b><i>' . $myrow['num'] . '</i><br><b>Номер ООС: </b><i>'; // Выводим элементы массива
			if ($myrow['oos'] != 0) // В ходе отладки выяснилось, что у небольшого количества процедур отсутствует ООС, добавим условие корректного вывода номера ООС
				echo $myrow['oos'] . '</i><br><b>Ссылка: </b><i>' . $myrow['link'] . '</i><br><b>E-mail: </b><i>' . $myrow['email'] . '</i><br>';
			else
				echo 'Нет номера ООС</i><br><b>Ссылка: </b><i>' . $myrow['link'] . '</i><br><b>E-mail: </b><i>' . $myrow['email'] . '</i><br>';
			if ($myrow['docs_count'] >> 0) { // Проверяем количество приложенных с процедуре документов
				for ($docs = 1; $docs <= $myrow['docs_count']; $docs++) { // Цикл по количеству документов
					$result = mysqli_query($connection, "SELECT name, link FROM documents WHERE (num = '$docs' AND proc_num='$numbers[$proc]')"); // Получаем строку из БД по номеру процедуры и номеру документа
					$myrow_doc = mysqli_fetch_array($result); // Записываем строку в массив
					echo '<b>Документ №' . $docs . ':</b> <i>' . $myrow_doc['name'] . '<br>' . $myrow_doc['link'] . '</i><br>'; // Выводим элементы массива
				}
			} else echo '<b>Документы отсутствуют.</b>';
			echo '<hr>';
		}
	}

	 function decode ($path) { // Функция декодировки из Unicode в UTF-8 
		return strtr($path, array("\u0430"=>"а", "\u0431"=>"б", "\u0432"=>"в", // Возвращаем соответствующий символ в ассоциативном массиве
		"\u0433"=>"г", "\u0434"=>"д", "\u0435"=>"е", "\u0451"=>"ё", "\u0436"=>"ж", "\u0437"=>"з", "\u0438"=>"и",
		"\u0439"=>"й", "\u043a"=>"к", "\u043b"=>"л", "\u043c"=>"м", "\u043d"=>"н", "\u043e"=>"о", "\u043f"=>"п",
		"\u0440"=>"р", "\u0441"=>"с", "\u0442"=>"т", "\u0443"=>"у", "\u0444"=>"ф", "\u0445"=>"х", "\u0446"=>"ц",
		"\u0447"=>"ч", "\u0448"=>"ш", "\u0449"=>"щ", "\u044a"=>"ъ", "\u044b"=>"ы", "\u044c"=>"ь", "\u044d"=>"э",
		"\u044e"=>"ю", "\u044f"=>"я", "\u0410"=>"А", "\u0411"=>"Б", "\u0412"=>"В", "\u0413"=>"Г", "\u0414"=>"Д",
		"\u0415"=>"Е", "\u0401"=>"Ё", "\u0416"=>"Ж", "\u0417"=>"З", "\u0418"=>"И", "\u0419"=>"Й", "\u041a"=>"К",
		"\u041b"=>"Л", "\u041c"=>"М", "\u041d"=>"Н", "\u041e"=>"О", "\u041f"=>"П", "\u0420"=>"Р", "\u0421"=>"С",
		"\u0422"=>"Т", "\u0423"=>"У", "\u0424"=>"Ф", "\u0425"=>"Х", "\u0426"=>"Ц", "\u0427"=>"Ч", "\u0428"=>"Ш",
		"\u0429"=>"Щ", "\u042a"=>"Ъ", "\u042b"=>"Ы", "\u042c"=>"Ь", "\u042d"=>"Э", "\u042e"=>"Ю", "\u042f"=>"Я"));
	}

?>