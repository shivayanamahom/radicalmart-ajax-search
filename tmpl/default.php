<?php
/**
 * @package     Joomla.Site
 * @subpackage  mod_radicalmart_search
 *
 * @copyright   (C) 2025-2026 Dharma Design
 * @license     GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

/**
 * Template variables
 * -----------------
 *
 * @var  object   $module Module object
 * @var  Registry $params Module params
 */

$placeholder = $params->get('placeholder', 'Поиск товаров...');
$buttonText = $params->get('button_text', 'Найти');
$minChars = (int) $params->get('min_chars', 2);
$delay = (int) $params->get('delay', 300);
$maxResults = max(1, min(50, (int) $params->get('max_results', 10)));
$ajaxUrl = Uri::root(true) . '/index.php?option=com_ajax&module=radicalmart_search&method=get&format=json&limit=' . $maxResults;
$texts = [
	'loading' => Text::_('MOD_RADICALMART_SEARCH_LOADING'),
	'empty'   => Text::_('MOD_RADICALMART_SEARCH_NO_RESULTS'),
	'error'   => Text::_('MOD_RADICALMART_SEARCH_ERROR'),
	'article' => Text::_('MOD_RADICALMART_SEARCH_ARTICLE'),
];
// Безопасная вставка значений в <script>: теги, амперсанд и кавычки кодируются.
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
$moduleId = $module->id;
$catalogUrl = $catalog_url ?? '';
?>

<div class="radicalmart-search-wrapper <?php echo $moduleclass_sfx; ?>">
	<div class="input-group position-relative">
		<input 
			type="text" 
			id="radicalmart-search-input-<?php echo $moduleId; ?>"
			class="form-control" 
			placeholder="<?php echo htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8'); ?>"
			autocomplete="off"
		/>
		<button type="button" class="btn btn-primary" id="radicalmart-search-btn-<?php echo $moduleId; ?>">
			<?php echo htmlspecialchars(Text::_($buttonText), ENT_QUOTES, 'UTF-8'); ?>
		</button>
		
		<!-- Контейнер для автодополнения -->
		<div id="radicalmart-search-results-<?php echo $moduleId; ?>" class="search-autocomplete-results"></div>
	</div>
</div>

<style>
.radicalmart-search-wrapper .input-group {
	margin-bottom: 0;
}

.search-autocomplete-results {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	background: #fff;
	border: 1px solid #ddd;
	border-top: none;
	max-height: 400px;
	overflow-y: auto;
	z-index: 1000;
	display: none;
	box-shadow: 0 4px 6px rgba(0,0,0,0.1);
	margin-top: -1px;
}

.search-autocomplete-results.show {
	display: block;
}

.search-autocomplete-item {
	padding: 12px 15px;
	cursor: pointer;
	border-bottom: 1px solid #f0f0f0;
	display: flex;
	align-items: center;
	gap: 12px;
	transition: background-color 0.2s;
	color: inherit;
}

.search-autocomplete-item:hover {
	background-color: #f8f9fa;
}

.search-autocomplete-item:last-child {
	border-bottom: none;
}

.search-autocomplete-item img {
	width: 50px;
	height: 50px;
	object-fit: cover;
	border-radius: 4px;
	flex-shrink: 0;
}

.search-autocomplete-item-info {
	flex: 1;
	min-width: 0;
}

.search-autocomplete-item-title {
	font-weight: 500;
	color: #333;
	margin-bottom: 4px;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.search-autocomplete-item-code {
	font-size: 12px;
	color: #777;
	margin-bottom: 2px;
}

.search-autocomplete-item-price {
	font-size: 14px;
	color: #28a745;
	font-weight: 600;
}

.search-autocomplete-loading,
.search-autocomplete-no-results {
	padding: 15px;
	text-align: center;
	color: #666;
}

.search-autocomplete-loading {
	font-style: italic;
}
</style>

<script>
(function() {
	'use strict';
	
	const moduleId = '<?php echo $moduleId; ?>';
	const minChars = <?php echo $minChars; ?>;
	const delay = <?php echo $delay; ?>;
	const ajaxUrl = <?php echo json_encode($ajaxUrl, $jsonFlags); ?>;
	const texts = <?php echo json_encode($texts, $jsonFlags); ?>;
	
	const searchInput = document.getElementById('radicalmart-search-input-' + moduleId);
	const searchBtn = document.getElementById('radicalmart-search-btn-' + moduleId);
	const resultsContainer = document.getElementById('radicalmart-search-results-' + moduleId);
	
	if (!searchInput || !resultsContainer) {
		return;
	}
	
	let searchTimeout;
	let activeRequest;
	
	// Функция для поиска товаров
	function searchProducts(query) {
		if (query.length < minChars) {
			resultsContainer.classList.remove('show');
			return;
		}
		
		// Показываем индикатор загрузки
		resultsContainer.innerHTML = '<div class="search-autocomplete-loading">' + escapeHtml(texts.loading) + '</div>';
		resultsContainer.classList.add('show');
		
		// Формируем URL для AJAX запроса через стандартный механизм Joomla
		const url = ajaxUrl + '&query=' + encodeURIComponent(query);
		
		// Устаревший запрос отменяем: на экране должен остаться ответ на последний ввод
		if (activeRequest) {
			activeRequest.abort();
		}

		activeRequest = new AbortController();

		// Выполняем запрос
		fetch(url, { signal: activeRequest.signal })
			.then(response => {
				if (!response.ok) {
					throw new Error('HTTP error ' + response.status);
				}
				return response.json();
			})
			.then(response => {
				// Joomla AJAX оборачивает данные в data
				const data = response.data || response;
				
				if (data.success && data.items) {
					displayResults(data);
				} else {
					// Текст ошибки сервера посетителю не показываем.
					resultsContainer.innerHTML = '<div class="search-autocomplete-no-results">' + escapeHtml(texts.error) + '</div>';
				}
			})
			.catch(error => {
				if (error && error.name === 'AbortError') {
					return;
				}

				resultsContainer.innerHTML = '<div class="search-autocomplete-no-results">' + escapeHtml(texts.error) + '</div>';
			});
	}
	
	// Функция для отображения результатов
	function displayResults(data) {
		if (!data || !data.items || data.items.length === 0) {
			resultsContainer.innerHTML = '<div class="search-autocomplete-no-results">' + escapeHtml(texts.empty) + '</div>';
			return;
		}
		
		let html = '';
		data.items.forEach(function(item) {
			const imageUrl = safeUrl(item.image || '');
			const price = item.price || '';
			// Строка цены от RadicalMart (с валютой или текстом вроде «Цена по запросу»)
			// важнее числа: так валюта не зашита в модуль.
			const priceFormatted = item.price_string ? String(item.price_string) : (price ? formatNumber(price) + ' ₽' : '');
			const codeLine = item.code ? escapeHtml(texts.article + ' ' + item.code) : '';
			
			// Все значения экранируются: ссылка и картинка идут в атрибуты.
			html += '<a href="' + escapeHtml(safeUrl(item.link || '#') || '#') + '" class="search-autocomplete-item text-decoration-none">';
			html += imageUrl ? '<img src="' + escapeHtml(imageUrl) + '" alt="' + escapeHtml(item.title) + '" />' : '<div style="width:50px;height:50px;background:#f0f0f0;border-radius:4px;display:flex;align-items:center;justify-content:center;"><svg width="24" height="24" fill="#999"><use href="#icon-image"/></svg></div>';
			html += '<div class="search-autocomplete-item-info">';
			html += '<div class="search-autocomplete-item-title">' + escapeHtml(item.title) + '</div>';
			html += codeLine ? '<div class="search-autocomplete-item-code">' + codeLine + '</div>' : '';
			html += priceFormatted ? '<div class="search-autocomplete-item-price">' + escapeHtml(priceFormatted) + '</div>' : '';
			html += '</div>';
			html += '</a>';
		});
		
		resultsContainer.innerHTML = html;
	}
	
	// Функция для форматирования числа с пробелами
	function formatNumber(num) {
		return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
	}
	
	// Функция для экранирования HTML (в том числе кавычек для атрибутов)
	function escapeHtml(text) {
		return String(text === null || text === undefined ? '' : text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}
	
	// Допускаем только http(s) и адреса от корня сайта; всё остальное (javascript:, data:) отбрасываем
	function safeUrl(url) {
		url = String(url || '').trim();
		return /^(https?:\/\/|\/(?!\/))/i.test(url) ? url : '';
	}
	
	// Обработчик ввода в поле поиска
	searchInput.addEventListener('input', function(e) {
		clearTimeout(searchTimeout);
		const query = e.target.value.trim();
		
		searchTimeout = setTimeout(function() {
			searchProducts(query);
		}, delay);
	});
	
	// Закрытие результатов при клике вне области
	document.addEventListener('click', function(e) {
		if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
			resultsContainer.classList.remove('show');
		}
	});
	
	// Показываем результаты при фокусе, если есть текст
	searchInput.addEventListener('focus', function() {
		if (this.value.trim().length >= minChars && resultsContainer.innerHTML) {
			resultsContainer.classList.add('show');
		}
	});
	
	// Переход по кнопке или Enter. Если запрос точно совпал с артикулом товара,
	// ведём сразу на страницу товара, иначе на каталог со штатным поиском RadicalMart.
	function submitSearch(query) {
		const catalogUrl = <?php echo json_encode($catalogUrl, $jsonFlags); ?>;

		const fallbackToCatalog = function() {
			if (!catalogUrl) {
				return;
			}

			const separator = catalogUrl.indexOf('?') !== -1 ? '&' : '?';
			window.location.href = catalogUrl + separator + 'filter[search]=' + encodeURIComponent(query);
		};

		fetch(ajaxUrl + '&query=' + encodeURIComponent(query))
			.then(response => response.ok ? response.json() : Promise.reject())
			.then(response => {
				const data = response.data || response;
				const exactItem = data.items && data.items.find(item => item.code_exact && safeUrl(item.link));

				if (exactItem) {
					window.location.href = safeUrl(exactItem.link);
					return;
				}

				fallbackToCatalog();
			})
			.catch(fallbackToCatalog);
	}
	
	// Обработчик кнопки поиска
	searchBtn.addEventListener('click', function() {
		const query = searchInput.value.trim();
		if (query.length >= minChars) {
			submitSearch(query);
		}
	});
	
	// Обработчик Enter в поле поиска
	searchInput.addEventListener('keypress', function(e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			searchBtn.click();
		}
	});
})();
</script>

