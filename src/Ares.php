<?php

namespace Defr;

use Defr\Ares\AresException;
use Defr\Ares\AresRecord;
use Defr\Ares\AresRecords;
use Defr\Ares\TaxRecord;
use SimpleXMLElement;



/**
 * Class Ares
 * @package Defr
 * @author Dennis Fridrich <fridrich.dennis@gmail.com>
 */
class Ares {

	const URL_BAS = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/darv_bas.cgi?ico=%s';

	const URL_RES = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/darv_res.cgi?ICO=%s';

	const URL_TAX = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/ares_es.cgi?ico=%s';

	const URL_FIND = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/ares_es.cgi?obch_jm=%s&obec=%s';

	const URL_ADRESS_NORMALIZER = 'http://wwwinfo.mfcr.cz/cgi-bin/ares/darv_adr.cgi?kraj=&pobvod=&okres=&psc=&obec=&ulice=&mestska_cast=&cislo_do_adresy=&max_pocet=20&cast_obce=&xml=0&jazyk=cz&adresa_textem=%s';

	/**
	 * @var string
	 */
	private $cacheStrategy = 'YW';

	/**
	 * @var string
	 */
	private $cacheDir = null;

	/**
	 * @var bool
	 */
	private $cacheEnabled = false;

	/**
	 * @var bool
	 */
	private $debug;



	/**
	 * @param null $cacheDir
	 * @param bool $debug
	 */
	public function __construct($cacheDir = null, $debug = false) {

		if ($cacheDir != null) {
			$this->cacheDir = $cacheDir . '/defr/ares';
			$this->debug = $debug;
			// Create cache dirs if they dont exist
			if (!is_dir($this->cacheDir)) {
				mkdir($this->cacheDir, 0777, true);
			}
			$this->cacheEnabled = true;
		}
	}



	/**
	 * @param $id
	 * @return AresRecord
	 * @throws \InvalidArgumentException
	 * @throws Ares\AresException
	 */
	public function findByIdentificationNumber($id) {

		$id = Lib::toInteger($id);

		if (!is_int($id)) {
			throw new \InvalidArgumentException('IČ firmy musí být číslo.');
		}

		// Sestaveni URL
		$url = sprintf(self::URL_BAS, $id);

		$cachedFileName = $id . '_' . date($this->cacheStrategy) . '.php';
		$cachedFile = $this->cacheDir . '/bas_' . $cachedFileName;
		$cachedRawFile = $this->cacheDir . '/bas_raw_' . $cachedFileName;

		if (!is_file($cachedFile) || !$this->cacheEnabled) {

			try {
				$aresRequest = file_get_contents($url);
				if ($this->debug && $this->cacheEnabled) {
					file_put_contents($cachedRawFile, $aresRequest);
				}
				$aresResponse = simplexml_load_string($aresRequest);

				if ($aresResponse) {
					$ns = $aresResponse->getDocNamespaces();
					$data = $aresResponse->children($ns['are']);
					$elements = $data->children($ns['D'])->VBAS;

					if (strval($elements->ICO) == $id) {
						$record = $this->createRecordFromBasElements($elements);
					} else {
						throw new AresException('IČ firmy nebylo nalezeno.');
					}
				} else {
					throw new AresException('Databáze ARES není dostupná.');
				}
			} catch (\Exception $e) {
				throw new AresException($e->getMessage());
			}

			if ($this->cacheEnabled) {
				file_put_contents($cachedFile, serialize($record));
			}
		} else {

			/** @var AresRecord $record */
			$record = unserialize(file_get_contents($cachedFile));
		}

		return $record;
	}



	/**
	 * @param SimpleXMLElement $elements
	 * @return \Defr\Ares\AresRecord
	 */
	protected function createRecordFromBasElements(SimpleXMLElement $elements) {
		$record = new AresRecord();
		$record->setCompanyId(strval($elements->ICO));
		$record->setTaxId(strval($elements->DIC));
		$record->setCompanyName(strval($elements->OF));

		if (!empty(strval($elements->AA->NU))) {
			$this->setRecordAdressFromBasElements($record, $elements);
		} elseif (!empty(strval($elements->AA->AT))) {
			$adressElements = $this->findAdressElementsFromText(strval($elements->AA->AT));
			$this->setRecordAdressFromAdressElements($record, $adressElements);
		}
		return $record;
	}



	/**
	 * @param string $adressText
	 * @return type
	 * @throws AresException
	 */
	protected function findAdressElementsFromText($adressText) {
		if (mb_detect_encoding($adressText) === 'UTF-8') {
			// Convert UTF8 to iso-8859-2
			$adressText = mb_convert_encoding($adressText, 'iso-8859-2', 'UTF-8');
		}
		// Encode adress to URL format
		$adressText = rawurlencode($adressText);
		// Build URL
		$url = sprintf(self::URL_ADRESS_NORMALIZER, $adressText);
		$aresRequest = file_get_contents($url);
		$aresResponse = simplexml_load_string($aresRequest);
		if ($aresResponse) {
			$ns = $aresResponse->getDocNamespaces();
			$data = $aresResponse->children($ns['are']);
			$elements = $data->children($ns['dtt'])->Stdadr_odpoved->Vsechna_slova->Seznam_navracenych->Adresa_ARES;

			if (!strval($elements->Kod_statu)) {
				throw new AresException('Adresa není platná.');
			}
		} else {
			throw new AresException('Databáze ARES není dostupná.');
		}
		return $elements;
	}



	/**
	 * @param \Defr\Ares\AresRecord $record
	 * @param SimpleXMLElement $elements
	 */
	protected function setRecordAdressFromBasElements($record, $elements) {
		$record->setStreet(strval($elements->AA->NU));

		if (strval($elements->AA->CO)) {
			$record->setStreetHouseNumber(strval($elements->AA->CD));
			$record->setStreetOrientationNumber(strval($elements->AA->CO));
		} else {
			$record->setStreetHouseNumber(strval($elements->AA->CD));
		}

		if (strval($elements->AA->NCO)) {
			$record->setTown(strval($elements->AA->N . ' - ' . strval($elements->AA->NCO)));
		} else {
			$record->setTown(strval($elements->AA->N));
		}
		$record->setZip(strval($elements->AA->PSC));
	}



	/**
	 * @param \Defr\Ares\AresRecord $record
	 * @param SimpleXMLElement $elements
	 */
	protected function setRecordAdressFromAdressElements($record, $elements) {
		$record->setStreet(strval($elements->Nazev_ulice));
		if (strval($elements->Cislo_orientacni)) {
			$record->setStreetHouseNumber(strval($elements->Cislo_domovni));
			$record->setStreetOrientationNumber(strval($elements->Cislo_orientacni));
		} else {
			$record->setStreetHouseNumber(strval($elements->Cislo_domovni));
		}
		if (strval($elements->Nazev_casti_obce)) {
			$record->setTown(strval($elements->Nazev_obce . ' - ' . strval($elements->Nazev_casti_obce)));
		} else {
			$record->setTown(strval($elements->Nazev_obce));
		}
		$record->setZip(strval($elements->PSC));
	}



	/**
	 * @param $id
	 * @return AresRecord
	 * @throws \InvalidArgumentException
	 * @throws Ares\AresException
	 */
	public function findInResById($id) {
		$id = Lib::toInteger($id);
		if (!is_int($id)) {
			throw new \InvalidArgumentException('IČ firmy musí být číslo.');
		}
		// Sestaveni URL
		$url = sprintf(self::URL_RES, $id);
		$cachedFileName = $id . '_' . date($this->cacheStrategy) . '.php';
		$cachedFile = $this->cacheDir . '/res_' . $cachedFileName;
		$cachedRawFile = $this->cacheDir . '/res_raw_' . $cachedFileName;
		if (!is_file($cachedFile) || !$this->cacheEnabled) {
			try {
				$aresRequest = file_get_contents($url);
				if ($this->debug && $this->cacheEnabled) {
					file_put_contents($cachedRawFile, $aresRequest);
				}
				$aresResponse = simplexml_load_string($aresRequest);

				if ($aresResponse) {
					$ns = $aresResponse->getDocNamespaces();
					$data = $aresResponse->children($ns['are']);
					$elements = $data->children($ns['D'])->Vypis_RES;

					if (strval($elements->ZAU->ICO) == $id) {
						$record = new AresRecord();
						$record->setCompanyId(strval($id));
						$record->setTaxId($this->findVatById($id));
						$record->setCompanyName(strval($elements->ZAU->OF));
						$record->setStreet(strval($elements->SI->NU));
						$record->setStreetHouseNumber(strval($elements->SI->CD));
						$record->setStreetOrientationNumber(strval($elements->SI->CO));
						$record->setTown(strval($elements->SI->N));
						$record->setZip(strval($elements->SI->PSC));
					} else {
						throw new AresException('IČ firmy nebylo nalezeno.');
					}
				} else {
					throw new AresException('Databáze ARES není dostupná.');
				}
			} catch (\Exception $e) {
				throw new AresException($e->getMessage());
			}
			if ($this->cacheEnabled) {
				file_put_contents($cachedFile, serialize($record));
			}
		} else {
			/** @var AresRecord $record */
			$record = unserialize(file_get_contents($cachedFile));
		}
		return $record;
	}



	/**
	 * @param $id
	 * @return TaxRecord|mixed
	 * @throws \InvalidArgumentException
	 * @throws \Exception
	 */
	public function findVatById($id) {

		$id = Lib::toInteger($id);

		if (!is_int($id)) {
			throw new \InvalidArgumentException('IČ firmy musí být číslo.');
		}

		// Sestaveni URL
		$url = sprintf(self::URL_TAX, $id);

		$cachedFileName = $id . '_' . date($this->cacheStrategy) . '.php';
		$cachedFile = $this->cacheDir . '/tax_' . $cachedFileName;
		$cachedRawFile = $this->cacheDir . '/tax_raw_' . $cachedFileName;

		if (!is_file($cachedFile) || !$this->cacheEnabled) {
			try {
				$vatRequest = file_get_contents($url);
				if ($this->debug && $this->cacheEnabled) {
					file_put_contents($cachedRawFile, $vatRequest);
				}
				$vatResponse = simplexml_load_string($vatRequest);

				if ($vatResponse) {
					$record = new TaxRecord();
					$ns = $vatResponse->getDocNamespaces();
					$data = $vatResponse->children($ns['are']);
					$elements = $data->children($ns['dtt'])->V->S;

					if (strval($elements->ico) == $id) {
						$record->setTaxId(str_replace('dic=', 'CZ', strval($elements->p_dph)));
					} else {
						throw new AresException('DIČ firmy nebylo nalezeno.');
					}
				} else {
					throw new AresException('Databáze MFČR není dostupná.');
				}
			} catch (\Exception $e) {
				throw new \Exception($e->getMessage());
			}
			if ($this->cacheEnabled) {
				file_put_contents($cachedFile, serialize($record));
			}
		} else {
			$record = unserialize(file_get_contents($cachedFile));
		}

		return $record;
	}



	/**
	 * @param $name
	 * @param null $city
	 * @return array|AresRecords
	 * @throws \InvalidArgumentException
	 * @throws \Exception
	 */
	public function findByName($name, $city = null) {

		if (strlen($name) < 3) {
			throw new \InvalidArgumentException('Zadejte minimálně tři znaky pro hledání.');
		}

		$url = sprintf(
			self::URL_FIND, urlencode(Lib::stripDiacritics($name)), urlencode(Lib::stripDiacritics($city))
		);

		$cachedFileName = date($this->cacheStrategy) . '_' . md5($name . $city) . '.php';
		$cachedFile = $this->cacheDir . '/find_' . $cachedFileName;
		$cachedRawFile = $this->cacheDir . '/find_raw_' . $cachedFileName;

		if (!is_file($cachedFile) || !$this->cacheEnabled) {
			try {
				$aresRequest = file_get_contents($url);
				if ($this->debug && $this->cacheEnabled) {
					file_put_contents($cachedRawFile, $aresRequest);
				}
				$aresResponse = simplexml_load_string($aresRequest);

				if ($aresResponse) {
					$ns = $aresResponse->getDocNamespaces();
					$data = $aresResponse->children($ns['are']);
					$elements = $data->children($ns['dtt'])->V->S;

					if (!count($elements)) {
						throw new AresException('Nic nebylo nalezeno.');
					} else {
						$records = new AresRecords();
						foreach ($elements as $element) {
							$record = new AresRecord();
							$record->setCompanyId(strval($element->ico));
							$record->setTaxId(
								($element->dph ? str_replace('dic=', 'CZ', strval($element->p_dph)) : '')
							);
							$record->setCompanyName(strval($element->ojm));
							//'adresa' => strval($element->jmn));
							$records[] = $record;
						}
					}
				} else {
					throw new AresException('Databáze ARES není dostupná.');
				}
			} catch (\Exception $e) {
				throw new \Exception($e->getMessage());
			}
			if ($this->cacheEnabled) {
				file_put_contents($cachedFile, serialize($records));
			}
		} else {
			$records = unserialize(file_get_contents($cachedFile));
		}

		return $records;
	}

}

