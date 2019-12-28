<?php
/*
Класс для работы с Curl
Все функции для вытаскивания данных с сайта
/************************ПРИМЕНЕНИЕ********************************
//если нужна авторизация
$options['autorization']=1;
$options['after_autoriz']='my';	
$options['login_page']=URL.'/login';

//поля формы ввода. атрибут name и его значение
$fields['username']='ayakov';
$fields['password']='kolbas';
$fields['serviceButtonValue']='login';
$options['fields']=$fields;
$options['cookie_patch']=COOKIE_PATH;
$options['url']=URL;
$pageText =new Curl($options);
$page=$pageText->get_page(URL.'zayavki/137340');

//если авторизация не нужна
$pageText =new Curl();
$page=$pageText->get_page(URL);
*/
class Curl {
  	const VERSION = 1.00;
	const autorization=0; //по умолчанию авторизация не требуется
	
	public function __construct($options=NULL) {
			//если требуется авторизация то нужно задать эти параметры при создании обьетк
			$this->url			=		$options['url']; //только url сайта
			$this->autorization = 		$options['autorization'];	//1-треубется авторизация
			$this->login_page = 		$options['login_page'];		//страница входа
			$this->after_autoriz = 		$options['after_autoriz'];	//страница после авторизации для того чтобы проверить успешно ли		
			$this->fields = 			$options['fields'];		//атрибуты формы и их значения
			$this->cookie_patch = 		$options['cookie_patch']."/cookie.txt";	//путь куда будем сохранять куки для авторизации
		return $this;
	}	
	
	//получение страницы
	public function get_page($url) { 
		if (!$this->autorization) {//если без авторизации то просто получаем страницу
			  $ch = curl_init($url);  
			  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);  
			  $page = curl_exec($ch);  
			  curl_close($ch); 
			  return $page; 
		} else {//если требуется сначала авторизация			
				$this->login();
				return $this->Read($url); //то получаем страницу с учетом кук
			
		}
	}
	
	//авторизация
	public function login(){			  
	  echo "<br>".date('H:i:s')." Сайт требует авторизации! ";		
	  echo "<br>".date('H:i:s')." Заходим на страницу входа  $this->login_page";
	   $ch = curl_init();
	   if(strtolower((substr($this->login_page,0,5))=='https')) { // если соединяемся с https
	   		
			echo "<br>".date('H:i:s')." В $this->login_page найден https но мы отключим сертификацию. ";	
			
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);// не проверять SSL сертификат
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);// не проверять Host SSL сертификата
	   }
	   
	   curl_setopt($ch, CURLOPT_URL, $this->login_page);// страница входа на которой форма авторизации
	   curl_setopt($ch, CURLOPT_REFERER, 'http://www.google.com/'); //делаем вид что перешли из google
	   curl_setopt($ch, CURLOPT_FAILONERROR, 1);// cURL будет выводить подробные сообщения о всех производимых действиях	   
	   curl_setopt($ch, CURLOPT_VERBOSE, 1);
	   curl_setopt($ch, CURLOPT_POST, 1); // использовать данные в post
	   curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // следовать за редиректами
	   curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);// таймаут выполнения авторизации	
	   curl_setopt($ch, CURLOPT_POSTFIELDS, $this->fields); //поля заполняемые при авторизации -их атрибуты <input name='login' value='vasia'>
	   curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (Windows; U; Windows NT 5.0; En; rv:1.8.0.2) Gecko/20070306 Firefox/1.0.0.4");
	   curl_setopt($ch, CURLOPT_HEADER, 1); //выводим заголовок чтобы по нему определить авторизовались или нет
	   curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // возвратить то что вернул сервер
	   curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:')); // это необходимо, чтобы cURL не высылал заголовок на ожидани   
	   curl_setopt($ch, CURLOPT_COOKIEJAR,  $this->cookie_patch);	//сохранять полученные COOKIE в файл	
		curl_setopt($ch, CURLOPT_COOKIEFILE,  $this->cookie_patch);
		
	   echo "<br>".date('H:i:s')." Выполняем авторизацию...";	
	   
	   $result=curl_exec($ch); //выполняем curl с указанными выше настройками
	   
	   if (curl_errno($ch)) {//если есть ошибки выводим их
		   print curl_error($ch);
		   exit;
	   }
	   
	   curl_close($ch);
	  
	   if(file_exists($this->cookie_patch)) 
		echo "<br>".date('H:i:s')." Cookie ЗАПИСАНЫ в файл: ".$this->cookie_patch;
	   else
		echo "<br>".date('H:i:s')." Cookie <strong>не</strong> записаны в файл: ".$this->cookie_patch;   
	   
		echo "<br>".date('H:i:s')." Проверяем мы на странице <strong>$this->after_autoriz</strong> после авторизации или нет ...";
		
	   // Убеждаемся что произошло перенаправление после авторизации
	   //ищем в результирующем файле строку с адресом успешной авторизации
	   if(strpos($result,"Location: ".$this->url.$this->after_autoriz)===false) {
			echo "<br>".date('H:i:s')." <strong>Ошибка авторизации!</strong> Заголовок <strong>Location: $this->after_autoriz</strong> не найден! Работа остановлена.<br><br>";
			return false;  
	   } else  { 	
			echo "<br>".date('H:i:s')." Авторизация прошла <strong>успешно</strong>!";
	   		return $result;
	   }
	}
	
	// чтение страницы после авторизации
	public function Read($url){
	  echo "<br>".date('H:i:s')." Теперь парсим требуемую страницу $url";
			
	   $ch = curl_init();
	   curl_setopt($ch, CURLOPT_URL, $url);
	   // откуда пришли на эту страницу
	   curl_setopt($ch, CURLOPT_REFERER, $this->login_page);
	   //запрещаем делать запрос с помощью POST и соответственно разрешаем с помощью GET
	   curl_setopt($ch, CURLOPT_POST, 0);
	   curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	   //отсылаем серверу COOKIE полученные от него при авторизации   
	   curl_setopt($ch, CURLOPT_COOKIEFILE,  $this->cookie_patch);
	   
	   curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/4.0 (Windows; U; Windows NT 5.0; En; rv:1.8.0.2) Gecko/20070306 Firefox/1.0.0.4");
	   curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // возвратить то что вернул сервер
	   curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:')); // это необходимо, чтобы cURL не высылал заголовок на ожидани  
	   
	   $result = curl_exec($ch);
	   curl_close($ch);
	   return $result;
	}


} //class