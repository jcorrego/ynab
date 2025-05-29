<?php

  
  /**
   * Process some data.
   *
   * @command infinite:test-data
   *
   * @usage drush infinite:test-data
   * @aliases in-td
   */
  public function testData(): void {
    // Get the current environment.
    $dev_client = SearchClient::create(
      "testing20MRCI4146",
      "9393e1aa3e02ddef14119a2441d6cd45",
    );
    $test_index = $dev_client->initIndex('fm_dt_1931_poc');
    $uat_index = $dev_client->initIndex('fm_product_en_uat');
    $uat_records = $uat_index->browseObjects();
    $removeKeys = [
      'weightUOM',
      'weight',
      "createdDate",
      "currencyCode",
      "defaultColor",
      "eCCN",
      "facets",
      "fccCodes",
      "fixedPrice",
      "fixedWeight",
      "hasCategory",
      "hasNCNR",
      "hierarchicalCategories",
      "inParametricSearch",
      "inventory",
      "isBaseCustomCableAssembly",
      "isBlockedForSale",
      "isCable",
      "isCableAssembly",
      "isConnector",
      "isCustomLengthAllowed",
      "isDiscontinued",
      "isInPlp",
      "isMasterCA",
      "isNew",
      "isOversized",
      "isProp65",
      "isPublished",
      "isPublishedOnConfigurator",
      "isSearchable",
      "isSellable",
      "isVariant",
      "keySpecs",
      "lastUpdatedTime",
      "length",
      "lengthVariations",
      "maxFreqMhz",
      "pdpLength",
      "pdpLengthVariations",
      "priceBreakLimit",
      "pricingTiers",
      "reachStatus",
      "replacementSKU",
      "revenue",
      "roHSStatus",
      "roHSStatusCode",
      "surchargeCode",
      "tSCAStatus",
      "unitPrice",
      "uom",
      "variablePrice",
      "variableWeight",
      "webDesc",
      "Maximum Insertion Loss(dB)",
      "Phase",
      "RF Max Frequency(GHz)",
      "RF Min Frequency(GHz)",
      "Typical Insertion Loss(dB)",
      "backOrders",
      "backorders",
      "bestSellerRank",
      "color",
      "colorVariations",
      "colourVariations",
      "compatibility",
      "configuratorData",
      "assets",
      "Body Style",
      "Connection Type",
      "Connector 1 Body Material",
      "Connector 1 Body Plating",
      "Connector 1 Connection Method",
      "Connector 1 Impedance(Ohms)",
      "Connector 1 Mount Method",
      "Connector 1 Polarity",
      "Connector 2 Body Material",
      "Connector 2 Body Plating",
      "Connector 2 Connection Method",
      "Connector 2 Impedance(Ohms)",
      "Connector 2 Mount Method",
      "Connector 2 Polarity",
      "Design",
      "Hermetically Sealed",
      "IP Rating",
      "Isolated Ground",
      "Max Frequency(GHz)",
      "Min Frequency(GHz)",
      "Passive Intermodulation(dBc)",
      "canCategory",
      "categorySEOURL",
    ];
    $insert_records = [];
    foreach ($uat_records as $hit) {
      if (isset($hit['category']) ) {//&& in_array('RF Adapters', $hit['category'])) {
        if (!isset($hit['Connector 1 Gender']) || !isset($hit['Connector 1 Series'])
        || !isset($hit['Connector 2 Gender']) || !isset($hit['Connector 2 Series'])) {
          continue;
        }
        $gender1 = $hit['Connector 1 Gender'];
        $gender2 = $hit['Connector 2 Gender'];
        $series1 = $hit['Connector 1 Series'];
        $series2 = $hit['Connector 2 Series'];
        $hit['Connector Gender'] = [$gender1 . ' to ' . $gender2];
        $hit['Connector Series'] = [$series1 . ' to ' . $series2];
        if ($gender1 != $gender2) {
          $hit['Connector Gender'][] = $gender2 . ' to ' . $gender1;
        }
        if ($series1 != $series2) {
          $hit['Connector Series'][] = $series2 . ' to ' . $series1;
        }
        foreach ($hit['assets'] as $asset) {
          if (isset($asset['type']) && $asset['type'] == 'MediumImage') {
            $hit['image'] = 'https://www.fairviewmicrowave.com/content/dam/infinite-electronics/product-assets/fairview-microwave/images/' . $asset['name'];
          }
        }
        foreach ($removeKeys as $key) {
          unset($hit[$key]);
        }
        $this->io()->writeln($hit['objectID']);
        $insert_records[] = $hit;
        // break;
      }
    }
    $chunk = 0;
    foreach (array_chunk($insert_records, 500) as $insert_records_chunk) {
      // $test_index->saveObjects($insert_records_chunk);
      $this->logger()->notice('Copied ' . ((500 * $chunk++) + count($insert_records_chunk)) . '/' . count($insert_records) . 'records');
    }
  }

  /**
   * Duplicate records with inverse attributes.
   *
   * @command infinite:duplicate
   *
   * @usage drush infinite:duplicate
   * @aliases in-dup
   */
  public function infiniteDuplicate(): void {
    // Get the current environment.
    $dev_client = SearchClient::create(
      "testing20MRCI4146",
      "9393e1aa3e02ddef14119a2441d6cd45",
    );
    $test_index = $dev_client->initIndex('fm_dt_2170_poc');
    $uat_index = $dev_client->initIndex('fm_product_en_uat');
    $uat_records = $uat_index->browseObjects();
    $removeKeys = [
      'weightUOM',
      'weight',
      "createdDate",
      "currencyCode",
      "defaultColor",
      "eCCN",
      "facets",
      "fccCodes",
      "fixedPrice",
      "fixedWeight",
      "hasCategory",
      "hasNCNR",
      "hierarchicalCategories",
      "inParametricSearch",
      "inventory",
      "isBaseCustomCableAssembly",
      "isBlockedForSale",
      "isCable",
      "isCableAssembly",
      "isConnector",
      "isCustomLengthAllowed",
      "isDiscontinued",
      "isInPlp",
      "isMasterCA",
      "isNew",
      "isOversized",
      "isProp65",
      "isPublished",
      "isPublishedOnConfigurator",
      "isSearchable",
      "isSellable",
      "isVariant",
      "keySpecs",
      "lastUpdatedTime",
      "length",
      "lengthVariations",
      "maxFreqMhz",
      "pdpLength",
      "pdpLengthVariations",
      "priceBreakLimit",
      "pricingTiers",
      "reachStatus",
      "replacementSKU",
      "revenue",
      "roHSStatus",
      "roHSStatusCode",
      "surchargeCode",
      "tSCAStatus",
      "unitPrice",
      "uom",
      "variablePrice",
      "variableWeight",
      "webDesc",
      "Maximum Insertion Loss(dB)",
      "Phase",
      "RF Max Frequency(GHz)",
      "RF Min Frequency(GHz)",
      "Typical Insertion Loss(dB)",
      "backOrders",
      "backorders",
      "bestSellerRank",
      "color",
      "colorVariations",
      "colourVariations",
      "compatibility",
      "configuratorData",
      "assets",
      "Body Style",
      "Connection Type",
      "Design",
      "Hermetically Sealed",
      "IP Rating",
      "Isolated Ground",
      "Max Frequency(GHz)",
      "Min Frequency(GHz)",
      "Passive Intermodulation(dBc)",
      "canCategory",
      "categorySEOURL",
      "Detector Polarity",
      "Power Max Input(dBm)",
      "Video Capacitance(pF)",
      "Tangential Sensitivity",
      "Voltage Sensitivity(mV/mW)",
    ];
    $duplicate_keys = [
      "series" => [
        "Connector 1 Series",
        "Connector 2 Series",
      ],
      "gender" => [
        "Connector 1 Gender",
        "Connector 2 Gender",
      ],
      "mount" => [
        "Connector 1 Mount Method",
        "Connector 2 Mount Method",
      ],
      "polarity" => [
        "Connector 1 Polarity",
        "Connector 2 Polarity",
      ],
      "impedance" => [
        "Connector 1 Impedance(Ohms)",
        "Connector 2 Impedance(Ohms)",
      ],
      "connection" => [
        "Connector 1 Connection Method",
        "Connector 2 Connection Method",
      ],
      "body" => [
        "Connector 1 Body Material",
        "Connector 2 Body Material",
      ],
      "plating" => [
        "Connector 1 Body Plating",
        "Connector 2 Body Plating",
      ],
    ];

    $insert_records = [];
    foreach ($uat_records as $hit) {
      if (isset($hit['category']) && in_array('RF Adapters', $hit['category'])) {
        if (!isset($hit['Connector 1 Gender']) || !isset($hit['Connector 1 Series'])
        || !isset($hit['Connector 2 Gender']) || !isset($hit['Connector 2 Series'])
        || empty($hit['Connector 1 Gender']) || empty($hit['Connector 1 Series'])
        || empty($hit['Connector 2 Gender']) || empty($hit['Connector 2 Series'])
        ) {
          continue;
        }
        foreach ($hit['assets'] as $asset) {
          if (isset($asset['type']) && $asset['type'] == 'MediumImage') {
            $hit['image'] = 'https://www.fairviewmicrowave.com/content/dam/infinite-electronics/product-assets/fairview-microwave/images/' . $asset['name'];
          }
        }
        foreach ($removeKeys as $key) {
          unset($hit[$key]);
        }
        $this->io()->writeln($hit['objectID']);
        $insert_records[] = $hit;
        $hit2 = $hit;
        foreach ($duplicate_keys as $key => $keys) {
          $value1 = $hit[$keys[0]];
          $value2 = $hit[$keys[1]];
          $hit2[$keys[0]] = $value2;
          $hit2[$keys[1]] = $value1;
        }
        $hit2['objectID'] = $hit2['objectID'] . '-reverse';
        $this->io()->writeln($hit2['objectID']);
        $insert_records[] = $hit2;
        // break;
      }
    }
    $chunk = 0;
    foreach (array_chunk($insert_records, 500) as $insert_records_chunk) {
      $test_index->saveObjects($insert_records_chunk);
      $this->logger()->notice('Copied ' . ((500 * $chunk++) + count($insert_records_chunk)) . '/' . count($insert_records) . ' records');
    }
  }

  /**
   * Process some data.
   *
   * @command infinite:backup
   *
   * @usage drush infinite:backup
   * @aliases in-bk
   */
  public function infiniteBackup(): void {
    // Get the current environment.
    $prod_client = SearchClient::create(
      "O0PAXP3VI5",
      "0e919089989fd33ae63fd7c95ab174d8",
    );
    $prod_index = $prod_client->initIndex('fm_product_en_prod');
    $backup_index = $prod_client->initIndex('fm_product_en_prod_backup');
    $backup_records = $backup_index->browseObjects();
    $insert_records = [];
    foreach ($backup_records as $hit) {
      $insert_records[] = $hit;
    }
    $chunk = 0;
    foreach (array_chunk($insert_records, 500) as $insert_records_chunk) {
      $prod_index->saveObjects($insert_records_chunk);
      $this->logger()->notice('Copied ' . ((500 * $chunk++) + count($insert_records_chunk)) . '/' . count($insert_records) . ' records');
    }
  }
  
  /**
   * Make interface attr multi value.
   *
   * @command infinite:multi-value
   *
   * @usage drush infinite:multi-value
   * @aliases in-mv
   */
  public function infiniteMultiValue(): void {
    
  }

}
