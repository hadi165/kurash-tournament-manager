<?php

namespace App\Support;

/**
 * Plausible entry lists for a demonstration championship.
 *
 * Names are built from real given and family name stock for each delegation,
 * because a screen full of "Athlete 1" tells you nothing about whether the
 * layout survives a long Cyrillic surname next to a short one. They are
 * combinations, not real competitors — no person in here is anybody.
 *
 * The delegations are the ones that actually turn up to a kurash championship:
 * Central Asia, Iran, Turkey, South Asia and the Caucasus.
 */
final class DemoRoster
{
    /**
     * NOC => [country, [male given], [female given], [family], [clubs]]
     *
     * @var array<string, array{name: string, m: list<string>, f: list<string>, s: list<string>, clubs: list<string>}>
     */
    private const NATIONS = [
        'UZB' => [
            'name' => 'Uzbekistan',
            'm' => ['Bekzod', 'Sardor', 'Jasur', 'Otabek', 'Doston', 'Aziz', 'Shohruh', 'Ulugbek', 'Farrukh', 'Javohir', 'Sanjar', 'Temur'],
            'f' => ['Nilufar', 'Gulnora', 'Zilola', 'Dilnoza', 'Sevara', 'Malika', 'Shahzoda', 'Ozoda', 'Nodira', 'Feruza'],
            's' => ['Qodirov', 'Tursunov', 'Rashidov', 'Yusupov', 'Ergashev', 'Abdullayev', 'Sultonov', 'Ismoilov', 'Nazarov', 'Xolmatov', 'Toshmatov', 'Karimov'],
            'clubs' => ['Dinamo Tashkent', 'Pakhtakor', 'Samarkand SK', 'Bukhoro Sport', 'Andijon KK'],
        ],
        'IRI' => [
            'name' => 'Islamic Republic of Iran',
            'm' => ['Hossein', 'Mohsen', 'Reza', 'Amir', 'Mehdi', 'Ali', 'Saeed', 'Hamid', 'Vahid', 'Milad', 'Kaveh', 'Behnam'],
            'f' => ['Zahra', 'Fatemeh', 'Maryam', 'Sara', 'Elham', 'Narges', 'Shirin', 'Parisa', 'Leila', 'Mahsa'],
            's' => ['Ansari', 'Rahimi', 'Mohammadi', 'Karimi', 'Hosseini', 'Jafari', 'Sadeghi', 'Ebrahimi', 'Rezaei', 'Moradi', 'Alavi', 'Naderi'],
            'clubs' => ['Shahrdari Tehran', 'Foolad Mobarakeh', 'Mes Kerman', 'Saipa Karaj', 'Zob Ahan'],
        ],
        'TJK' => [
            'name' => 'Tajikistan',
            'm' => ['Rustam', 'Firuz', 'Dilshod', 'Bahrom', 'Nasim', 'Komron', 'Sherali', 'Umed', 'Parviz', 'Shohin'],
            'f' => ['Farzona', 'Manizha', 'Nigina', 'Sitora', 'Rukhshona', 'Zarina', 'Anisa', 'Munisa'],
            's' => ['Nazarov', 'Rahmonov', 'Sharipov', 'Qurbonov', 'Safarov', 'Davlatov', 'Aliev', 'Yusufi', 'Odinaev', 'Mirzoev'],
            'clubs' => ['Istiqlol', 'Khatlon SK', 'Dushanbe Olympic', 'Sughd Sport'],
        ],
        'KAZ' => [
            'name' => 'Kazakhstan',
            'm' => ['Aidos', 'Yerlan', 'Nurlan', 'Daniyar', 'Askar', 'Arman', 'Bekzat', 'Talgat', 'Ruslan', 'Serik'],
            'f' => ['Aigerim', 'Zhanar', 'Dana', 'Madina', 'Aliya', 'Gulnaz', 'Assel', 'Karina'],
            's' => ['Zhaksylyk', 'Abaev', 'Sultanov', 'Beisenov', 'Amanzhol', 'Nurpeisov', 'Kaliev', 'Toktarov', 'Seitkali', 'Dosanov'],
            'clubs' => ['Astana Sport', 'Almaty Olympic', 'Shymkent KK', 'Aktobe Dinamo'],
        ],
        'KGZ' => [
            'name' => 'Kyrgyzstan',
            'm' => ['Nurbek', 'Aibek', 'Ulan', 'Erlan', 'Bakyt', 'Azamat', 'Tilek', 'Kanat'],
            'f' => ['Aizada', 'Nazgul', 'Meerim', 'Aiperi', 'Cholpon', 'Begimai'],
            's' => ['Beksultan', 'Toktogulov', 'Zhumabekov', 'Osmonov', 'Sadyrov', 'Asanov', 'Kadyrov', 'Baatyrov'],
            'clubs' => ['Bishkek Dinamo', 'Osh Sport', 'Naryn KK'],
        ],
        'TKM' => [
            'name' => 'Turkmenistan',
            'm' => ['Dovlet', 'Serdar', 'Merdan', 'Guvanch', 'Batyr', 'Rustem', 'Kerim'],
            'f' => ['Oguljan', 'Maysa', 'Jennet', 'Aylar', 'Gozel'],
            's' => ['Annayev', 'Karimov', 'Berdiyev', 'Hojayev', 'Amanov', 'Saparov', 'Muhammedov'],
            'clubs' => ['Ashgabat Sport', 'Mary Olympic', 'Dashoguz KK'],
        ],
        'IND' => [
            'name' => 'India',
            'm' => ['Arjun', 'Vikram', 'Rohit', 'Sandeep', 'Manpreet', 'Karan', 'Ravi', 'Ajay', 'Nikhil', 'Harpreet'],
            'f' => ['Priya', 'Anjali', 'Kavita', 'Simran', 'Pooja', 'Neha', 'Ritu', 'Sunita'],
            's' => ['Deshmukh', 'Sharma', 'Singh', 'Patil', 'Chauhan', 'Yadav', 'Rathore', 'Malik', 'Thakur', 'Gill'],
            'clubs' => ['Pune Kurash Club', 'Delhi Sports Authority', 'Punjab Wrestling', 'Haryana Akhara'],
        ],
        'TUR' => [
            'name' => 'Türkiye',
            'm' => ['Emre', 'Mustafa', 'Burak', 'Yusuf', 'Kerem', 'Onur', 'Serkan', 'Kaan', 'Baris'],
            'f' => ['Zeynep', 'Elif', 'Merve', 'Ayse', 'Buse', 'Ebru', 'Selin'],
            's' => ['Yildirim', 'Aksoy', 'Demir', 'Kaya', 'Sahin', 'Ozturk', 'Arslan', 'Dogan', 'Celik'],
            'clubs' => ['Ankara Güres', 'Istanbul BB', 'Izmir Sport', 'Konya Belediye'],
        ],
        'AZE' => [
            'name' => 'Azerbaijan',
            'm' => ['Elnur', 'Rashad', 'Orkhan', 'Tural', 'Kamran', 'Nijat', 'Elvin'],
            'f' => ['Aysel', 'Gunel', 'Lala', 'Nigar', 'Sevinc'],
            's' => ['Mammadov', 'Aliyev', 'Huseynov', 'Guliyev', 'Ismayilov', 'Hasanov', 'Rzayev'],
            'clubs' => ['Neftchi Baku', 'Ganja Olympic', 'Sumgait SK'],
        ],
        'MGL' => [
            'name' => 'Mongolia',
            'm' => ['Batbold', 'Ganbat', 'Enkhbat', 'Tserendorj', 'Munkhbat', 'Bold'],
            'f' => ['Oyunaa', 'Saruul', 'Bolormaa', 'Enkhtuya', 'Nomin'],
            's' => ['Ganbaatar', 'Batsaikhan', 'Erdene', 'Dorj', 'Tumen', 'Nergui'],
            'clubs' => ['Ulaanbaatar SK', 'Darkhan Sport', 'Erdenet KK'],
        ],
        'PAK' => [
            'name' => 'Pakistan',
            'm' => ['Bilal', 'Usman', 'Hamza', 'Zeeshan', 'Adnan', 'Imran'],
            'f' => ['Ayesha', 'Sana', 'Hina', 'Maria'],
            's' => ['Khan', 'Ahmed', 'Iqbal', 'Rashid', 'Butt', 'Aslam'],
            'clubs' => ['Lahore Sports Board', 'Karachi Wrestling', 'Peshawar Club'],
        ],
        'AFG' => [
            'name' => 'Afghanistan',
            'm' => ['Ahmad', 'Farid', 'Najib', 'Wahid', 'Sami', 'Rahmat'],
            'f' => ['Marina', 'Nadia', 'Freshta', 'Laila'],
            's' => ['Ghaznavi', 'Rahimi', 'Noori', 'Hashimi', 'Sadat', 'Popal'],
            'clubs' => ['Kabul Olympic', 'Herat Sport', 'Balkh KK'],
        ],
    ];

    /** @return list<string> */
    public static function nocs(): array
    {
        return array_keys(self::NATIONS);
    }

    public static function countryName(string $noc): string
    {
        $name = self::NATIONS[$noc]['name'] ?? null;

        return is_string($name) ? $name : $noc;
    }

    /**
     * A name for this delegation that has not been used yet.
     *
     * @param  array<string, true>  $taken  names already issued, by reference
     */
    public static function name(string $noc, string $gender, array &$taken): string
    {
        $nation = self::NATIONS[$noc];
        $given = $gender === 'F' ? $nation['f'] : $nation['m'];

        // Bounded: with the smallest pool here that is still several hundred
        // combinations, and the fallback keeps it terminating regardless.
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $name = $given[array_rand($given)].' '.$nation['s'][array_rand($nation['s'])];

            if (! isset($taken[$name])) {
                $taken[$name] = true;

                return $name;
            }
        }

        $name = $given[array_rand($given)].' '.$nation['s'][array_rand($nation['s'])].' '.(count($taken) + 1);
        $taken[$name] = true;

        return $name;
    }

    public static function club(string $noc): string
    {
        $clubs = self::NATIONS[$noc]['clubs'];

        return $clubs[array_rand($clubs)];
    }
}
