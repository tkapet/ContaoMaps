<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * PHP version 5
 * @copyright  Cyberspectrum 2012
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @package    ContaoMaps
 * @license    LGPL
 * @filesource
 */

// internal class for rendering only - not a real module but mimics one to some extend.
class ModuleCatalogWrapperContaoMap extends ModuleCatalogList
{
	protected $strTemplate='contaomap_catalog';

	protected $items;
	protected $omitMarkers;

	public function __construct($objLayer, $omitMarkers, $objMapper)
	{
		parent::__construct($objLayer);
		$this->catalog_visible = deserialize($this->catalog_visible);
		$this->omitMarkers=$omitMarkers;
		$this->mapper = $objMapper;
	}

	public function generate($latLngFilter='')
	{
		$this->latLngFilter= $latLngFilter;
		parent::generate();
		return $this->items;
	}

	protected function processFieldSQL(array $arrVisible, $intCatalog, $strTable, $blnNoAlias = false)
	{
		$arrConverted = parent::processFieldSQL($arrVisible, $intCatalog, $strTable, $blnNoAlias);
		if($this->latLngFilter)
		{
			$arrConverted[] = 'tl_catalog_geolocation.latitude AS latitude';
			$arrConverted[] = 'tl_catalog_geolocation.longitude AS longitude';
			$arrConverted[] = 'tl_catalog_geolocation.id AS locid';
		}
		return $arrConverted;
	}

	protected function compile()
	{
		$cols=array();
		$cols = $this->processFieldSQL($this->catalog_visible, $this->catalog, $this->strTable);
		if($this->catalog_iconfield)
			$cols[] = $this->catalog_iconfield;
		if($this->strAliasField)
			$cols[] = $this->strAliasField;

		$filterurl = $this->parseFilterUrl($this->catalog_visible);
		// Query Catalog
		$filterurl = $this->addSearchFilter($filterurl);
		$arrParams = $this->generateStmtParams($filterurl);
		$strWhere = $this->generateStmtWhere($filterurl);
		if($this->omitMarkers)
			$strWhere .= ($strWhere?' AND ':''). $this->strTable.'.id NOT IN ('.implode(',', $this->omitMarkers).')';
		if($this->latLngFilter)
		{
			$arrJoins=array(
				sprintf('RIGHT JOIN tl_catalog_geolocation ON (cat_id=%d AND item_id={{table}}.id)',
				$this->catalog
			));
			$strWhere .= ($strWhere?' AND ':'') . $this->latLngFilter;
		}
		$this->catalog_visible = $cols;
		$strOrder = $this->generateStmtOrderBy($filterurl);
		$objCatalog = $this->fetchItems(0, 0, $strWhere, $strOrder, $arrParams, $arrJoins);

//		echo '/*'.$objCatalog->query.'*/';
		$items=$this->generateCatalog($objCatalog, true, $this->catalog_visible);
		$objCatalog->reset();
		$i=0;
		while($objCatalog->next())
		{
			if($this->catalog_iconfield)
				$items[$i][$this->catalog_iconfield]=$objCatalog->{$this->catalog_iconfield};
			$items[$i]['longitude']=$objCatalog->longitude;
			$items[$i++]['latitude']=$objCatalog->latitude;
		}
		$this->items=$items;
	}
}

class ImageSizer extends Controller
{
	public function getImage($image, $width, $height, $mode='', $target=null)
	{
		return parent::getImage($image, $width, $height, $mode, $target);
	}
}

/**
 * Class ContaoMapLayerCatalog - add markers from a catalog to a map.
 *
 * @copyright  Cyberspectrum 2009
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @package    Controller
 */
class ContaoMapLayerCatalog extends ContaoMapLayer
{
	public function assembleObjects($omitObjects)
	{
		$strClass=$GLOBALS['CONTAOMAP_MAPOBJECTS']['marker'];
		if(!$strClass)
			return;
		$objLayer = $this->Database->prepare('SELECT * FROM tl_contaomap_layer WHERE id=?')->execute($this->id);
		$omitIds=($omitObjects['marker'])?filter_var_array($omitObjects['marker'], FILTER_SANITIZE_NUMBER_INT):array();

		$renderer = new ModuleCatalogWrapperContaoMap($objLayer, $omitIds, $this);
		$area = ($objLayer->ignore_area_filter? '': $this->getAreaFilter('latitude', 'longitude'));
		$items=$renderer->generate($area?$area:'');

		// TODO: we have to find a better way to select icon images than this.
		// Definately!
		// Backend config would be nice, but which one? Reader? Fieldconfig? Catalog itself?
		$item=$items[0];
		$iconsize = deserialize($objLayer->imageSize);
		$objSizer = new ImageSizer();
		foreach($items as $i=>$item)
		{
			if($objLayer->catalog_icon && file_exists(TL_ROOT . '/'. $objLayer->catalog_icon))
			{
				$icon = $objLayer->catalog_icon;
			} else {
				unset($icon);
			}

			$tpl = new FrontendTemplate($objLayer->catalog_template);
			$tpl->entries=array($item);
			$objMarker = new $strClass(array(
				'jsid' => 'marker_'.$item['id'],
				'infotext' => $tpl->parse()
			));
			if($objLayer->catalog_iconfield && $item[$objLayer->catalog_iconfield])
			{
				// TODO: ensure that only one image is in the field, no image gallery
				$objMarker->icon = $objSizer->getImage($this->urlEncode($item[$objLayer->catalog_iconfield]), $iconsize[0], $iconsize[1], $iconsize[2]);
			} elseif($icon)
			{
				$objMarker->icon = $objSizer->getImage($this->urlEncode($icon), $iconsize[0], $iconsize[1], $iconsize[2]);
			}
			if($objMarker->icon)
			{
				$objIcon = new File($objMarker->icon);
				$objMarker->iconsize = $objIcon->width.','.$objIcon->height;
				$objMarker->iconposition = sprintf('%s,%s', floor($objIcon->width/2), floor($objIcon->height/2));
			}
			$objMarker->latitude = $item['latitude'];
			$objMarker->longitude = $item['longitude'];

			$this->add($objMarker);
		}
	}
}

?>
