<?php
if (!function_exists('listOtterSocialMedia')) {
    /**
     * Return OtterNova’s Social Media accounts
     * @return array
     */
    function listOtterSocialMedia(): array
    {
        return [
            "twitter-x" => getenv("OTTER_TWITTER"),
            "facebook"  => getenv("OTTER_FACEBOOK"),
            "instagram" => getenv("OTTER_INSTAGRAM"),
            "linkedin"  => getenv("OTTER_LINKEDIN"),
        ];
    }
}
if (!function_exists("listSocialMediaKeys")) {
    /**
     * List social media keys that retrieve the BI class
     * @return array
     */
    function listSocialMediaKeys(): array
    {
        return [
            "twitter-x" => "X",
            "facebook"  => "Facebook",
            "instagram" => "Instagram",
            "linkedin"  => "LinkedIn",
            "youtube"   => "Youtube",
            "globe"     => "Website",
        ];
    }
}
if (!function_exists("listSupportedLanguages")) {
    /**
     * Return the languages supported by OtterNova’s UI
     * @return array
     */
    function listSupportedLanguages(): array
    {
        return [
            "en-US" => "English (US)",
            "th"    => "ภาษาไทย (Thai)",
        ];
    }
}
if (!function_exists("listLanguages")) {
    /**
     * List all languages
     * TODO: add more languages
     * @return array
     */
    function listLanguages(): array
    {
        return [
            "en-US" => "English (US)",
            "en-GB" => "English (GB)",
            "en-AU" => "English (AU)",
            "id"    => "Bahasa Indonesia",
            "ms"    => "Bahasa Malaysia",
            "vi"    => "tiếng Việt (Vietnamese)",
            "th"    => "ภาษาไทย (Thai)",
            "zh-SG" => "华语 (Huayu)",
            "ja"    => "日本語 (Japanese)",
            "ko"    => "한국어 (Korean)",
            "zh-TW" => "國語 (Mandarin)",
            "ta"    => "தமிழ் (Tamil)",
            "su"    => "ᮘᮞ ᮞᮥᮔ᮪ᮓ / Basa Sunda (Sundanese)",
            "jv"    => "ꦧꦱꦗꦮ / Basa Jawa (Javanese)"
        ];
    }
}
if (!function_exists("listSupportedCountries")) {
    /**
     * This is the list of countries where clients can pick up as the main business country.
     * IMPORTANT:
     * TODO:
     * To add to this list, the timezones, subdivisions, and currencies must be added together.
     * Also, translate the country names in the listCountries() function.
     * @return array
     */
    function listSupportedCountries(): array
    {
        return [
            "ID" => "Indonesia",
            "MY" => "Malaysia",
            "PH" => "Philippines",
            "SG" => "Singapore",
            "VN" => "Việt Nam (Vietnam)",
            "TH" => "ประเทศไทย (Thailand)",
            "TW" => "台灣 (Taiwan)",
            "JP" => "日本 (Japan)",
            "KR" => "한국 (South Korea)",
        ];
    }
}
if (!function_exists("splitCountryListByRegion")) {
    /**
     * This function returns the array of supported country codes by region
     * @return array
     */
    function splitCountryListByRegion(): array
    {
        return [
            "Southeast Asia" => ["ID", "MY", "PH", "SG", "TH", "VN",],
            "East Asia"      => ["JP", "KR", "TW",],
        ];
    }
}
if (!function_exists("listCountries")) {
    /**
     * List all recognized countries on earth that are not sanctioned.
     * @return array
     */
    function listCountries(): array
    {
        return [
            "AL" => "Albania",
            "DZ" => "Algeria",
            "AS" => "American Samoa",
            "AD" => "Andorra",
            "AO" => "Angola",
            "AR" => "Argentina",
            "AM" => "Armenia",
            "AU" => "Australia",
            "AT" => "Austria",
            "AZ" => "Azerbaijan",
            "BS" => "Bahamas",
            "BH" => "Bahrain",
            "BD" => "Bangladesh",
            "BB" => "Barbados",
            "BE" => "Belgium",
            "BZ" => "Belize",
            "BJ" => "Benin",
            "BM" => "Bermuda",
            "BT" => "Bhutan",
            "BO" => "Bolivia",
            "BA" => "Bosnia and Herzegovina",
            "BW" => "Botswana",
            "BR" => "Brazil",
            "BN" => "Brunei Darussalam",
            "BG" => "Bulgaria",
            "BF" => "Burkina Faso",
            "BI" => "Burundi",
            "KH" => "Cambodia",
            "CM" => "Cameroon",
            "CA" => "Canada",
            "CV" => "Cabo Verde",
            "KY" => "Cayman Islands",
            "CF" => "Central African Republic",
            "TD" => "Chad",
            "CL" => "Chile",
            "CO" => "Colombia",
            "KM" => "Comoros",
            "CG" => "Congo",
            "CD" => "Congo, The Democratic Republic of the",
            "CK" => "Cook Islands",
            "CR" => "Costa Rica",
            "CI" => "Cote D’Ivoire",
            "HR" => "Croatia",
            "CU" => "Cuba",
            "CY" => "Cyprus",
            "CZ" => "Czech Republic",
            "DK" => "Denmark",
            "DJ" => "Djibouti",
            "DM" => "Dominica",
            "DO" => "Dominican Republic",
            "EC" => "Ecuador",
            "EG" => "Egypt",
            "SV" => "El Salvador",
            "GQ" => "Equatorial Guinea",
            "ER" => "Eritrea",
            "EE" => "Estonia",
            "ET" => "Ethiopia",
            "FK" => "Falkland Islands (Malvinas)",
            "FO" => "Faroe Islands",
            "FJ" => "Fiji",
            "FI" => "Finland",
            "FR" => "France",
            "GF" => "French Guiana",
            "PF" => "French Polynesia",
            "GA" => "Gabon",
            "GM" => "Gambia",
            "GE" => "Georgia",
            "DE" => "Germany",
            "GH" => "Ghana",
            "GI" => "Gibraltar",
            "GR" => "Greece",
            "GL" => "Greenland",
            "GD" => "Grenada",
            "GP" => "Guadeloupe",
            "GU" => "Guam",
            "GT" => "Guatemala",
            "GG" => "Guernsey",
            "GN" => "Guinea",
            "GW" => "Guinea-Bissau",
            "GY" => "Guyana",
            "HT" => "Haiti",
            "HN" => "Honduras",
            "HU" => "Hungary",
            "IS" => "Iceland",
            "IN" => "India",
            "ID" => "Indonesia",
            "IE" => "Ireland",
            "IM" => "Isle of Man",
            "IL" => "Israel",
            "IT" => "Italy",
            "JM" => "Jamaica",
            "JP" => "日本 (Japan)",
            "JE" => "Jersey",
            "JO" => "Jordan",
            "KZ" => "Kazakhstan",
            "KE" => "Kenya",
            "KI" => "Kiribati",
            "KR" => "한국 (South Korea)",
            "KW" => "Kuwait",
            "KG" => "Kyrgyzstan",
            "LA" => "Laos",
            "LV" => "Latvia",
            "LB" => "Lebanon",
            "LS" => "Lesotho",
            "LR" => "Liberia",
            "LY" => "Libya",
            "LI" => "Liechtenstein",
            "LT" => "Lithuania",
            "LU" => "Luxembourg",
            "MK" => "North Macedonia",
            "MG" => "Madagascar",
            "MW" => "Malawi",
            "MY" => "Malaysia",
            "MV" => "Maldives",
            "ML" => "Mali",
            "MT" => "Malta",
            "MH" => "Marshall Islands",
            "MQ" => "Martinique",
            "MR" => "Mauritania",
            "MU" => "Mauritius",
            "YT" => "Mayotte",
            "MX" => "Mexico",
            "FM" => "Micronesia",
            "MD" => "Moldova",
            "MC" => "Monaco",
            "MN" => "Mongolia",
            "ME" => "Montenegro",
            "MA" => "Morocco",
            "MZ" => "Mozambique",
            "NA" => "Namibia",
            "NR" => "Nauru",
            "NP" => "Nepal",
            "NL" => "Netherlands",
            "NC" => "New Caledonia",
            "NZ" => "New Zealand",
            "NI" => "Nicaragua",
            "NE" => "Niger",
            "NG" => "Nigeria",
            "NU" => "Niue",
            "MP" => "Northern Mariana Islands",
            "NO" => "Norway",
            "OM" => "Oman",
            "PK" => "Pakistan",
            "PW" => "Palau",
            "PS" => "Palestine",
            "PA" => "Panama",
            "PG" => "Papua New Guinea",
            "PY" => "Paraguay",
            "PE" => "Peru",
            "PH" => "Philippines",
            "PL" => "Poland",
            "PT" => "Portugal",
            "PR" => "Puerto Rico",
            "QA" => "Qatar",
            "RE" => "Reunion",
            "RO" => "Romania",
            "RW" => "Rwanda",
            "KN" => "Saint Kitts and Nevis",
            "LC" => "Saint Lucia",
            "VC" => "Saint Vincent and the Grenadines",
            "WS" => "Samoa",
            "SM" => "San Marino",
            "ST" => "Sao Tome and Principe",
            "SA" => "Saudi Arabia",
            "SN" => "Senegal",
            "RS" => "Serbia",
            "SC" => "Seychelles",
            "SL" => "Sierra Leone",
            "SG" => "Singapore",
            "SK" => "Slovakia",
            "SI" => "Slovenia",
            "SB" => "Solomon Islands",
            "SO" => "Somalia",
            "ZA" => "South Africa",
            "SS" => "South Sudan",
            "ES" => "Spain",
            "LK" => "Sri Lanka",
            "SD" => "Sudan",
            "SR" => "Suriname",
            "SZ" => "Eswatini",
            "SE" => "Sweden",
            "CH" => "Switzerland",
            "TW" => "台灣 (Taiwan)",
            "TJ" => "Tajikistan",
            "TZ" => "Tanzania",
            "TH" => "ประเทศไทย (Thailand)",
            "TL" => "Timor-Leste",
            "TG" => "Togo",
            "TO" => "Tonga",
            "TT" => "Trinidad and Tobago",
            "TN" => "Tunisia",
            "TR" => "Türkiye",
            "TM" => "Turkmenistan",
            "TC" => "Turks and Caicos Islands",
            "TV" => "Tuvalu",
            "UG" => "Uganda",
            "UA" => "Ukraine",
            "AE" => "United Arab Emirates",
            "GB" => "United Kingdom",
            "US" => "United States",
            "UY" => "Uruguay",
            "UZ" => "Uzbekistan",
            "VU" => "Vanuatu",
            "VE" => "Venezuela",
            "VN" => "Việt Nam (Vietnam)",
            "VG" => "Virgin Islands, British",
            "VI" => "Virgin Islands, U.S.",
            "EH" => "Western Sahara",
            "ZM" => "Zambia",
            "ZW" => "Zimbabwe",
        ];
    }
}
if (!function_exists("listSupportedTimezones")) {
    /**
     * List the supported timezones
     * Filter by country if the $countryCode parameter is not null
     * @param string $countryCode (optional)
     * @return array
     */
    function listSupportedTimezones(string $countryCode = ''): array
    {
        $timezones = [
            // Southeast Asia
            "TH" => [
                "Asia/Bangkok" => "ประเทศไทย (Thailand)"
            ],
            "SG" => [
                "Asia/Singapore" => "Singapore"
            ],
            "MY" => [
                "Asia/Kuala_Lumpur" => "Malaysia"
            ],
            "ID" => [
                "Asia/Jakarta"  => "WIB; Jakarta, Medan (Western)",
                "Asia/Makassar" => "WITA; Bali (Central)",
                "Asia/Jayapura" => "WIT; Papua (Eastern)"
            ],
            "PH" => [
                "Asia/Manila" => "Philippines"
            ],
            "VN" => [
                "Asia/Ho_Chi_Minh" => "Việt Nam (Vietnam)"
            ],
            // East Asia
            "JP" => [
                "Asia/Tokyo" => "日本 (Japan)"
            ],
            "TW" => [
                "Asia/Taipei" => "台灣 (Taiwan)"
            ],
            "KR" => [
                "Asia/Seoul" => "한국 (South Korea)"
            ],
        ];
        if (empty($countryCode)) {
            return $timezones;
        }
        $countryCode = strtoupper($countryCode);
        // If not exist, just return empty array to indicate the timezone is not set for the country
        return $timezones[$countryCode] ?? [];
    }
}
if (!function_exists("listSupportedSubdivisions")) {
    /**
     * List the supported subdivisions
     * Filter by country if the $countryCode parameter is not null
     * @param string $countryCode (optional)
     * @return array
     */
    function listSupportedSubdivisions(string $countryCode = ''): array
    {
        $subdivisions = [
            // Southeast Asia
            "TH" => [
                "TH-10" => "กรุงเทพมหานคร (Bangkok)",
                "TH-81" => "กระบี่ (Krabi)",
                "TH-71" => "กาญจนบุรี (Kanchanaburi)",
                "TH-46" => "กาฬสินธุ์ (Kalasin)",
                "TH-62" => "กำแพงเพชร (Kamphaeng Phet)",
                "TH-40" => "ขอนแก่น (Khon Kaen)",
                "TH-22" => "จันทบุรี (Chanthaburi)",
                "TH-24" => "ฉะเชิงเทรา (Chachoengsao)",
                "TH-20" => "ชลบุรี (Chon Buri)",
                "TH-18" => "ชัยนาท (Chai Nat)",
                "TH-36" => "ชัยภูมิ (Chaiyaphum)",
                "TH-86" => "ชุมพร (Chumphon)",
                "TH-92" => "ตรัง (Trang)",
                "TH-23" => "ตราด (Trat)",
                "TH-63" => "ตาก (Tak)",
                "TH-26" => "นครนายก (Nakhon Nayok)",
                "TH-73" => "นครปฐม (Nakhon Pathom)",
                "TH-48" => "นครพนม (Nakhon Phanom)",
                "TH-30" => "นครราชสีมา (Nakhon Ratchasima)",
                "TH-80" => "นครศรีธรรมราช (Nakhon Si Thammarat)",
                "TH-60" => "นครสวรรค์ (Nakhon Sawan)",
                "TH-12" => "นนทบุรี (Nonthaburi)",
                "TH-96" => "นราธิวาส (Narathiwat)",
                "TH-55" => "น่าน (Nan)",
                "TH-38" => "บึงกาฬ (Bueng Kan)",
                "TH-31" => "บุรีรัมย์ (Buri Ram)",
                "TH-13" => "ปทุมธานี (Pathum Thani)",
                "TH-77" => "ประจวบคีรีขันธ์ (Prachuap Khiri Khan)",
                "TH-25" => "ปราจีนบุรี (Prachin Buri)",
                "TH-94" => "ปัตตานี (Pattani)",
                "TH-14" => "พระนครศรีอยุธยา (Ayutthaya)",
                "TH-56" => "พะเยา (Phayao)",
                "TH-82" => "พังงา (Phangnga)",
                "TH-93" => "พัทลุง (Phatthalung)",
                "TH-66" => "พิจิตร (Phichit)",
                "TH-65" => "พิษณุโลก (Phitsanulok)",
                "TH-83" => "ภูเก็ต (Phuket)",
                "TH-44" => "มหาสารคาม (Maha Sarakham)",
                "TH-49" => "มุกดาหาร (Mukdahan)",
                "TH-95" => "ยะลา (Yala)",
                "TH-35" => "ยโสธร (Yasothon)",
                "TH-85" => "ระนอง (Ranong)",
                "TH-21" => "ระยอง (Rayong)",
                "TH-70" => "ราชบุรี (Ratchaburi)",
                "TH-45" => "ร้อยเอ็ด (Roi Et)",
                "TH-16" => "ลพบุรี (Lop Buri)",
                "TH-52" => "ลำปาง (Lampang)",
                "TH-51" => "ลำพูน (Lamphun)",
                "TH-33" => "ศรีสะเกษ (Si Sa Ket)",
                "TH-47" => "สกลนคร (Sakon Nakhon)",
                "TH-90" => "สงขลา (Songkhla)",
                "TH-91" => "สตูล (Satun)",
                "TH-11" => "สมุทรปราการ (Samut Prakan)",
                "TH-75" => "สมุทรสงคราม (Samut Songkhram)",
                "TH-74" => "สมุทรสาคร (Samut Sakhon)",
                "TH-19" => "สระบุรี (Saraburi)",
                "TH-27" => "สระแก้ว (Sa Kaeo)",
                "TH-17" => "สิงห์บุรี (Sing Buri)",
                "TH-72" => "สุพรรณบุรี (Suphan Buri)",
                "TH-84" => "สุราษฎร์ธานี (Surat Thani)",
                "TH-32" => "สุรินทร์ (Surin)",
                "TH-64" => "สุโขทัย (Sukhothai)",
                "TH-43" => "หนองคาย (Nong Khai)",
                "TH-39" => "หนองบัวลำภู (Nong Bua Lam Phu)",
                "TH-37" => "อำนาจเจริญ (Amnat Charoen)",
                "TH-41" => "อุดรธานี (Udon Thani)",
                "TH-53" => "อุตรดิตถ์ (Uttaradit)",
                "TH-61" => "อุทัยธานี (Uthai Thani)",
                "TH-34" => "อุบลราชธานี (Ubon Ratchathani)",
                "TH-15" => "อ่างทอง (Ang Thong)",
                "TH-57" => "เชียงราย (Chiang Rai)",
                "TH-50" => "เชียงใหม่ (Chiang Mai)",
                "TH-76" => "เพชรบุรี (Phetchaburi)",
                "TH-67" => "เพชรบูรณ์ (Phetchabun)",
                "TH-42" => "เลย (Loei)",
                "TH-54" => "แพร่ (Phrae)",
                "TH-58" => "แม่ฮ่องสอน (Mae Hong Son)",
            ],
            "SG" => [
                "SG" => "Singapore",
            ],
            "MY" => [
                "MY-01" => "Johor",
                "MY-02" => "Kedah",
                "MY-03" => "Kelantan",
                "MY-14" => "Kuala Lumpur",
                "MY-15" => "Labuan",
                "MY-04" => "Melaka",
                "MY-05" => "Negeri Sembilan",
                "MY-06" => "Pahang",
                "MY-08" => "Perak",
                "MY-09" => "Perlis",
                "MY-07" => "Pulau Pinang",
                "MY-16" => "Putrajaya",
                "MY-12" => "Sabah",
                "MY-13" => "Sarawak",
                "MY-10" => "Selangor",
                "MY-11" => "Terengganu",
            ],
            "ID" => [
                "ID-AC" => "Aceh",
                "ID-BA" => "Bali",
                "ID-BB" => "Bangka Belitung Islands",
                "ID-BT" => "Banten",
                "ID-BE" => "Bengkulu",
                "ID-JT" => "Central Java",
                "ID-KT" => "Central Kalimantan",
                "ID-PT" => "Central Papua",
                "ID-ST" => "Central Sulawesi",
                "ID-JI" => "East Java",
                "ID-KI" => "East Kalimantan",
                "ID-NT" => "East Nusa Tenggara",
                "ID-GO" => "Gorontalo",
                "ID-PE" => "Highland Papua",
                "ID-JK" => "Jakarta",
                "ID-JA" => "Jambi",
                "ID-LA" => "Lampung",
                "ID-MA" => "Maluku",
                "ID-KU" => "North Kalimantan",
                "ID-MU" => "North Maluku",
                "ID-SA" => "North Sulawesi",
                "ID-SU" => "North Sumatra",
                "ID-PA" => "Papua",
                "ID-RI" => "Riau",
                "ID-KR" => "Riau Islands",
                "ID-KS" => "South Kalimantan",
                "ID-PS" => "South Papua",
                "ID-SN" => "South Sulawesi",
                "ID-SS" => "South Sumatra",
                "ID-SG" => "Southeast Sulawesi",
                "ID-PD" => "Southwest Papua",
                "ID-JB" => "West Java",
                "ID-KB" => "West Kalimantan",
                "ID-NB" => "West Nusa Tenggara",
                "ID-PB" => "West Papua",
                "ID-SR" => "West Sulawesi",
                "ID-SB" => "West Sumatra",
                "ID-YO" => "Yogyakarta",
            ],
            "PH" => [
                "PH-ABR" => "Abra",
                "PH-AGN" => "Agusan del Norte",
                "PH-AGS" => "Agusan del Sur",
                "PH-AKL" => "Aklan",
                "PH-ALB" => "Albay",
                "PH-ANT" => "Antique",
                "PH-APA" => "Apayao",
                "PH-AUR" => "Aurora",
                "PH-BAS" => "Basilan",
                "PH-BAN" => "Bataan",
                "PH-BTN" => "Batanes",
                "PH-BTG" => "Batangas",
                "PH-BEN" => "Benguet",
                "PH-BIL" => "Biliran",
                "PH-BOH" => "Bohol",
                "PH-BUK" => "Bukidnon",
                "PH-BUL" => "Bulacan",
                "PH-CAG" => "Cagayan",
                "PH-CAN" => "Camarines Norte",
                "PH-CAS" => "Camarines Sur",
                "PH-CAM" => "Camiguin",
                "PH-CAP" => "Capiz",
                "PH-CAT" => "Catanduanes",
                "PH-CAV" => "Cavite",
                "PH-CEB" => "Cebu",
                "PH-NCO" => "Cotabato",
                "PH-DVO" => "Davao Occidental",
                "PH-DAO" => "Davao Oriental",
                "PH-COM" => "Davao de Oro",
                "PH-DAV" => "Davao del Norte",
                "PH-DAS" => "Davao del Sur",
                "PH-DIN" => "Dinagat Islands",
                "PH-EAS" => "Eastern Samar",
                "PH-GUI" => "Guimaras",
                "PH-IFU" => "Ifugao",
                "PH-ILN" => "Ilocos Norte",
                "PH-ILS" => "Ilocos Sur",
                "PH-ILI" => "Iloilo",
                "PH-ISA" => "Isabela",
                "PH-KAL" => "Kalinga",
                "PH-LUN" => "La Union",
                "PH-LAG" => "Laguna",
                "PH-LAN" => "Lanao del Norte",
                "PH-LAS" => "Lanao del Sur",
                "PH-LEY" => "Leyte",
                "PH-MGN" => "Maguindanao del Norte",
                "PH-MGS" => "Maguindanao del Sur",
                "PH-MAD" => "Marinduque",
                "PH-MAS" => "Masbate",
                "PH-MDC" => "Mindoro Occidental",
                "PH-MDR" => "Mindoro Oriental",
                "PH-MSC" => "Misamis Occidental",
                "PH-MSR" => "Misamis Oriental",
                "PH-MOU" => "Mountain Province",
                "PH-NEC" => "Negros Occidental",
                "PH-NER" => "Negros Oriental",
                "PH-NSA" => "Northern Samar",
                "PH-NUE" => "Nueva Ecija",
                "PH-NUV" => "Nueva Vizcaya",
                "PH-PLW" => "Palawan",
                "PH-PAM" => "Pampanga",
                "PH-PAN" => "Pangasinan",
                "PH-QUE" => "Quezon",
                "PH-QUI" => "Quirino",
                "PH-RIZ" => "Rizal",
                "PH-ROM" => "Romblon",
                "PH-WSA" => "Samar",
                "PH-SAR" => "Sarangani",
                "PH-SIG" => "Siquijor",
                "PH-SOR" => "Sorsogon",
                "PH-SCO" => "South Cotabato",
                "PH-SLE" => "Southern Leyte",
                "PH-SUK" => "Sultan Kudarat",
                "PH-SLU" => "Sulu",
                "PH-SUN" => "Surigao del Norte",
                "PH-SUR" => "Surigao del Sur",
                "PH-TAR" => "Tarlac",
                "PH-TAW" => "Tawi-Tawi",
                "PH-ZMB" => "Zambales",
                "PH-ZSI" => "Zamboanga Sibugay",
                "PH-ZAN" => "Zamboanga del Norte",
                "PH-ZAS" => "Zamboanga del Sur",
            ],
            "VN" => [
                "VN-44" => "An Giang",
                "VN-43" => "Bà Rịa - Vũng Tàu",
                "VN-57" => "Bình Dương",
                "VN-58" => "Bình Phước",
                "VN-40" => "Bình Thuận",
                "VN-31" => "Bình Định",
                "VN-55" => "Bạc Liêu",
                "VN-54" => "Bắc Giang",
                "VN-53" => "Bắc Kạn",
                "VN-56" => "Bắc Ninh",
                "VN-50" => "Bến Tre",
                "VN-04" => "Cao Bằng",
                "VN-59" => "Cà Mau",
                "VN-CT" => "Cần Thơ",
                "VN-30" => "Gia Lai",
                "VN-03" => "Hà Giang",
                "VN-63" => "Hà Nam",
                "VN-HN" => "Hà Nội",
                "VN-23" => "Hà Tĩnh",
                "VN-14" => "Hòa Bình",
                "VN-66" => "Hưng Yên",
                "VN-61" => "Hải Dương",
                "VN-HP" => "Hải Phòng",
                "VN-73" => "Hậu Giang",
                "VN-SG" => "Hồ Chí Minh (Sài Gòn)",
                "VN-34" => "Khánh Hòa",
                "VN-47" => "Kiến Giang",
                "VN-28" => "Kon Tum",
                "VN-01" => "Lai Châu",
                "VN-41" => "Long An",
                "VN-02" => "Lào Cai",
                "VN-35" => "Lâm Đồng",
                "VN-09" => "Lạng Sơn",
                "VN-67" => "Nam Định",
                "VN-22" => "Nghệ An",
                "VN-18" => "Ninh Bình",
                "VN-36" => "Ninh Thuận",
                "VN-68" => "Phú Thọ",
                "VN-32" => "Phú Yên",
                "VN-24" => "Quảng Bình",
                "VN-27" => "Quảng Nam",
                "VN-29" => "Quảng Ngãi",
                "VN-13" => "Quảng Ninh",
                "VN-25" => "Quảng Trị",
                "VN-52" => "Sóc Trăng",
                "VN-05" => "Sơn La",
                "VN-21" => "Thanh Hóa",
                "VN-20" => "Thái Bình",
                "VN-69" => "Thái Nguyên",
                "VN-26" => "Thừa Thiên-Huế",
                "VN-46" => "Tiền Giang",
                "VN-51" => "Trà Vinh",
                "VN-07" => "Tuyên Quang",
                "VN-37" => "Tây Ninh",
                "VN-49" => "Vĩnh Long",
                "VN-70" => "Vĩnh Phúc",
                "VN-06" => "Yên Bái",
                "VN-71" => "Điện Biên",
                "VN-DN" => "Đà Nẵng",
                "VN-33" => "Đắk Lắk",
                "VN-72" => "Đắk Nông",
                "VN-39" => "Đồng Nai",
                "VN-45" => "Đồng Tháp",
            ],
            // East Asia
            "JP" => [
                "JP-24" => "三重 Mie",
                "JP-26" => "京都 Kyoto",
                "JP-41" => "佐賀 Saga",
                "JP-28" => "兵庫 Hyogo",
                "JP-01" => "北海道 Hokkaido",
                "JP-12" => "千葉 Chiba",
                "JP-30" => "和歌山 Wakayama",
                "JP-11" => "埼玉 Saitama",
                "JP-44" => "大分 Oita",
                "JP-27" => "大阪 Osaka",
                "JP-29" => "奈良 Nara",
                "JP-04" => "宮城 Miyagi",
                "JP-45" => "宮崎 Miyazaki",
                "JP-16" => "富山 Toyama",
                "JP-35" => "山口 Yamaguchi",
                "JP-06" => "山形 Yamagata",
                "JP-19" => "山梨 Yamanashi",
                "JP-21" => "岐阜 Gifu",
                "JP-33" => "岡山 Okayama",
                "JP-03" => "岩手 Iwate",
                "JP-32" => "島根 Shimane",
                "JP-34" => "広島 Hiroshima",
                "JP-36" => "徳島 Tokushima",
                "JP-38" => "愛媛 Ehime",
                "JP-23" => "愛知 Aichi",
                "JP-15" => "新潟 Niigata",
                "JP-13" => "東京 Tokyo",
                "JP-09" => "栃木 Tochigi",
                "JP-47" => "沖縄 Okinawa",
                "JP-25" => "滋賀 Shiga",
                "JP-43" => "熊本 Kumamoto",
                "JP-17" => "石川 Ishikawa",
                "JP-14" => "神奈川 Kanagawa",
                "JP-18" => "福井 Fukui",
                "JP-40" => "福岡 Fukuoka",
                "JP-07" => "福島 Fukushima",
                "JP-05" => "秋田 Akita",
                "JP-10" => "群馬 Gunma",
                "JP-08" => "茨城 Ibaraki",
                "JP-42" => "長崎 Nagasaki",
                "JP-20" => "長野 Nagano",
                "JP-02" => "青森 Aomori",
                "JP-22" => "静岡 Shizuoka",
                "JP-37" => "香川 Kagawa",
                "JP-39" => "高知 Kochi",
                "JP-31" => "鳥取 Tottori",
                "JP-46" => "鹿児島 Kagoshima",
            ],
            "TW" => [
                "TW-NAN" => "南投 Nantou",
                "TW-CYI" => "嘉義 Chiayi",
                "TW-CYQ" => "嘉義 Chiayi",
                "TW-KEE" => "基隆 Keelung",
                "TW-ILA" => "宜蘭 Yilan",
                "TW-PIF" => "屏東 Pingtung",
                "TW-CHA" => "彰化 Changhua",
                "TW-NWT" => "新北 New Taipei",
                "TW-HSZ" => "新竹 Hsinchu",
                "TW-HSQ" => "新竹 Hsinchu",
                "TW-TAO" => "桃園 Taoyuan",
                "TW-PEN" => "澎湖 Penghu",
                "TW-TXG" => "臺中 Taichung",
                "TW-TPE" => "臺北 Taipei",
                "TW-TNN" => "臺南 Tainan",
                "TW-TTT" => "臺東 Taitung",
                "TW-HUA" => "花蓮 Hualien",
                "TW-MIA" => "苗栗 Miaoli",
                "TW-LIE" => "連江 Lienchiang",
                "TW-KIN" => "金門 Kinmen",
                "TW-YUN" => "雲林 Yunlin",
                "TW-KHH" => "高雄 Kaohsiung",
            ],
            "KR" => [
                "KR-42" => "강원 Gangwon",
                "KR-41" => "경기도 Gyeonggi",
                "KR-48" => "경상남 South Gyeongsang",
                "KR-47" => "경상북 North Gyeongsang",
                "KR-29" => "광주 Gwangju",
                "KR-27" => "대구 Daegu",
                "KR-30" => "대전 Daejeon",
                "KR-26" => "부산 Busan",
                "KR-11" => "서울 Seoul",
                "KR-50" => "세종 Sejong",
                "KR-31" => "울산 Ulsan",
                "KR-28" => "인천 Incheon",
                "KR-46" => "전라남 South Jeolla",
                "KR-45" => "전라북 North Jeolla",
                "KR-49" => "제주 Jeju",
                "KR-44" => "충청남 South Chungcheong",
                "KR-43" => "충청북 North Chungcheong",
            ],
        ];
        if (empty($countryCode)) {
            return $subdivisions;
        }
        $countryCode = strtoupper($countryCode);
        // If not exist, just return empty array to indicate the subdivision is not set for the country
        return $subdivisions[$countryCode] ?? [];
    }
}
if (!function_exists('listSupportedCurrencies')) {
    /**
     * List the supported currencies
     * Filter by country if the $countryCode parameter is not null
     * @param string $countryCode
     * @return array
     */
    function listSupportedCurrencies(string $countryCode = ''): array
    {
        $currencies = [
            "ID" => ["IDR", "USD"],
            "MY" => ["MYR", "USD"],
            "PH" => ["PHP", "USD"],
            "SG" => ["SGD", "USD"],
            "VN" => ["VND", "USD"],
            "TH" => ["THB", "USD"],
            "JP" => ["JPY", "USD"],
            "KR" => ["KRW", "USD"],
            "TW" => ["TWD", "USD"],
        ];
        if (empty($countryCode)) {
            return $currencies;
        }
        $countryCode = strtoupper($countryCode);
        // If not exist, just return empty array to indicate the currency is not set for the country
        return $currencies[$countryCode] ?? [];
    }
}
if (!function_exists('mapCurrencyCode')) {
    /**
     * This function is used to map the ISO4217 code to the currency name
     * The parameter $currencyCode is required
     * @param string $currencyCode
     * @return string
     */
    function mapCurrencyCode(string $currencyCode): string
    {
        $currencies = [
            "IDR" => "Indonesian rupiah",
            "MYR" => "Malaysian ringgit",
            "PHP" => "Philippine peso",
            "SGD" => "Singapore dollar",
            "VND" => "Đồng (Vietnamese dong)",
            "THB" => "บาท (Thai baht)",
            "JPY" => "円 (Japanese yen)",
            "KRW" => "원 (South Korean won)",
            "TWD" => "元 (New Taiwan dollar)",
            "USD" => "US Dollar",
        ];
        $currencyCode = strtoupper($currencyCode);
        return $currencies[$currencyCode] ?? '';
    }
}