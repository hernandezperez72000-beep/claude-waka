<?php
declare(strict_types=1);

/**
 * El padrón completo de sitios del Perú con los que arranca el HUB.
 *
 * 25 departamentos, 196 provincias y 1893 distritos: el padrón entero, no una
 * muestra. Antes había 79 distritos (Lima, Callao y Arequipa) y una promesa de
 * «si tu distrito no está, se añade desde el buscador» que nunca se construyó:
 * una venta a Cusco o a Piura NO SE PODÍA REGISTRAR.
 *
 * LA CAPITAL IMPORTA TANTO COMO EL NOMBRE. 357 distritos —uno de cada cinco—
 * tienen una capital que se llama distinto, y la gente habla con el nombre de
 * la capital: nadie dice «mándalo a Chanchamayo», dicen «a La Merced». Lo
 * encontró el usuario buscando La Merced y no encontrándola. Por eso un
 * distrito puede ser una cadena suelta o un par [distrito, capital]: la
 * capital no cambia cómo se llama el sitio, solo permite encontrarlo.
 *
 * Sobre las tildes: el padrón oficial viene en MAYÚSCULAS y sin ellas. Los 79
 * distritos que ya estaban conservan la grafía cuidada que tenían; los nuevos
 * van como vienen. `busca` quita las tildes antes de comparar, así que «Ancón»
 * y «Ancon» encuentran lo mismo.
 *
 * Formato: departamento => [ provincia => [ distrito | [distrito, capital] ] ].
 *
 * ESTE ARCHIVO NO SE SIEMBRA SOLO en un HUB ya instalado: sembrar_ubigeo()
 * corta si la tabla tiene filas. Para eso están las migraciones de una sola
 * vez de esquema3.php. Si se amplía este padrón hace falta una marca nueva.
 */
function ubigeo_peru(): array
{
    return [
        'Amazonas' => [
            'Bagua' => [
                'Aramango','Bagua','Copallin','El Parco',['Imaza', 'Chiriaco'],'La Peca',
            ],
            'Bongara' => [
                'Chisquilla','Churuja','Corosha','Cuispes',['Florida', 'Florida (Pomacochas)'],
                ['Jazan', 'Pedro Ruiz Gallo'],'Jumbilla','Recta','San Carlos','Shipasbamba',
                ['Valera', 'Valera (San Pablo)'],'Yambrasbamba',
            ],
            'Chachapoyas' => [
                'Asuncion','Balsas','Chachapoyas','Cheto','Chiliquin','Chuquibamba','Granada',
                'Huancas','La Jalca','Leimebamba','Levanto','Magdalena',
                ['Mariscal Castilla', 'Duraznopampa'],'Molinopampa','Montevideo','Olleros',
                'Quinjalca',['San Francisco de Daguas', 'Daguas'],['San Isidro de Maino', 'Maino'],
                'Soloco',['Sonche', 'San Juan de Sonche'],
            ],
            'Condorcanqui' => [
                ['El Cenepa', 'Huampami'],['Nieva', 'Santa María de Nieva'],
                ['Rio Santiago', 'Puerto Galilea'],
            ],
            'Luya' => [
                'Camporredondo','Cocabamba','Colcamar',['Conila', 'Cohechan'],'Inguilpata','Lamud',
                'Longuita','Lonya Chico','Luya','Luya Viejo','Maria','Ocalli',
                ['Ocumal', 'Collonce'],['Pisuquia', 'Yomblon'],'Providencia',
                ['San Cristobal', 'Olto'],'San Francisco del Yeso',['San Jeronimo', 'Paclas'],
                'San Juan de Lopecancha','Santa Catalina','Santo Tomas','Tingo','Trita',
            ],
            'Rodriguez de Mendoza' => [
                'Chirimoto','Cochamal','Huambo','Limabamba','Longar','Mariscal Benavides','Milpuc',
                'Omia',['San Nicolas', 'Mendoza'],['Santa Rosa', 'Santa Rosa de
 Huayabamba'],
                'Totora','Vista Alegre',
            ],
            'Utcubamba' => [
                'Bagua Grande','Cajaruro','Cumba','El Milagro','Jamalca','Lonya Grande','Yamon',
            ],
        ],
        'Áncash' => [
            'Aija' => [
                'Aija','Coris','Huacllan','La Merced','Succha',
            ],
            'Antonio Raymondi' => [
                'Aczo','Chaccho','Chingas','Llamellin','Mirgas','San Juan de Rontoy',
            ],
            'Asuncion' => [
                'Acochaca','Chacas',
            ],
            'Bolognesi' => [
                ['Abelardo Pardo Lezameta', 'Llaclla'],['Antonio Raymondi', 'Raquia'],'Aquia',
                'Cajacay','Canis','Chiquian',['Colquioc', 'Chasquitambo'],'Huallanca','Huasta',
                'Huayllacayan',['La Primavera', 'Gorgorillo'],'Mangas','Pacllon',
                ['San Miguel de Corpanqui', 'Corpanqui'],'Ticllos',
            ],
            'Carhuaz' => [
                'Acopampa','Amashca','Anta',['Ataquero', 'Carhuac'],'Carhuaz','Marcara',
                'Pariahuanca',['San Miguel de Aco', 'Aco'],'Shilla','Tinco','Yungar',
            ],
            'Carlos Fermin Fitzcarrald' => [
                'San Luis','San Nicolas','Yauya',
            ],
            'Casma' => [
                'Buena Vista Alta','Casma',['Comandante Noel', 'Puerto Casma'],'Yautan',
            ],
            'Corongo' => [
                'Aco','Bambas','Corongo','Cusca','La Pampa','Yanac','Yupan',
            ],
            'Huaraz' => [
                'Cochabamba','Colcabamba','Huanchay','Huaraz',['Independencia', 'Centenario'],
                'Jangas',['La Libertad', 'Cajamarquilla'],'Olleros',['Pampas', 'Pampas Grande'],
                'Pariacoto','Pira','Tarica',
            ],
            'Huari' => [
                'Anra','Cajay','Chavin de Huantar','Huacachi','Huacchis','Huachis','Huantar',
                'Huari','Masin','Paucas','Ponto','Rahuapampa','Rapayan','San Marcos',
                ['San Pedro de Chana', 'Chana'],'Uco',
            ],
            'Huarmey' => [
                'Cochapeti',['Culebras', 'La Caleta Culebras'],'Huarmey','Huayan','Malvas',
            ],
            'Huaylas' => [
                'Caraz','Huallanca','Huata','Huaylas',['Mato', 'Sucre'],'Pamparomas','Pueblo Libre',
                ['Santa Cruz', 'Huaripampa'],'Santo Toribio','Yuracmarca',
            ],
            'Mariscal Luzuriaga' => [
                'Casca',['Eleazar Guzman Barron', 'Pampachacra'],
                ['Fidel Olivas Escudero', 'Sanachgan'],'Llama','Llumpa','Lucma','Musga',
                'Piscobamba',
            ],
            'Ocros' => [
                'Acas','Cajamarquilla',['Carhuapampa', 'Aco'],['Cochas', 'Huanchay'],'Congas',
                'Llipa','Ocros',['San Cristobal de Rajan', 'Rajan'],['San Pedro', 'Copa'],
                'Santiago de Chilcas',
            ],
            'Pallasca' => [
                'Bolognesi','Cabana','Conchucos','Huacaschuque','Huandoval','Lacabamba','Llapo',
                'Pallasca','Pampas','Santa Rosa','Tauca',
            ],
            'Pomabamba' => [
                'Huayllan','Parobamba','Pomabamba','Quinuabamba',
            ],
            'Recuay' => [
                'Catac','Cotaparaco','Huayllapampa','Llacllin','Marca','Pampas Chico','Pararin',
                'Recuay','Tapacocha','Ticapampa',
            ],
            'Santa' => [
                ['Caceres del Peru', 'Jimbe'],'Chimbote','Coishco','Macate','Moro','Nepeña',
                ['Nuevo Chimbote', 'Buenos Aires'],'Samanco','Santa',
            ],
            'Sihuas' => [
                'Acobamba',['Alfonso Ugarte', 'Ullulluco'],'Cashapampa','Chingalpo','Huayllabamba',
                'Quiches','Ragash',['San Juan', 'Chullin'],['Sicsibamba', 'Umbe'],'Sihuas',
            ],
            'Yungay' => [
                'Cascapara','Mancos','Matacoto','Quillo','Ranrahirca','Shupluy','Yanama','Yungay',
            ],
        ],
        'Apurímac' => [
            'Abancay' => [
                'Abancay','Chacoche','Circa','Curahuasi','Huanipaca','Lambrama','Pichirhua',
                ['San Pedro de Cachora', 'Cachora'],'Tamburco',
            ],
            'Andahuaylas' => [
                'Andahuaylas','Andarapa','Chiara','Huancarama','Huancaray','Huayana',
                ['Jose Maria Arguedas', 'Huancabamba'],'Kaquiabamba','Kishuara','Pacobamba',
                'Pacucha','Pampachiri','Pomacocha','San Antonio de Cachi','San Jeronimo',
                ['San Miguel de Chaccrampa', 'Chaccrampa'],'Santa Maria de Chicmo','Talavera',
                ['Tumay Huaraca', 'Umamarca'],'Turpo',
            ],
            'Antabamba' => [
                'Antabamba',['El Oro', 'Ayahuay'],'Huaquirca',
                ['Juan Espinoza Medrano', 'Mollebamba'],'Oropesa','Pachaconas','Sabaino',
            ],
            'Aymaraes' => [
                'Capaya','Caraybamba','Chalhuanca','Chapimarca','Colcabamba','Cotaruse','Huayllo',
                ['Justo Apu Sahuaraura', 'Pichihua'],'Lucre','Pocohuanca','San Juan de Chacña',
                'Sañayca','Soraya','Tapairihua','Tintay','Toraya','Yanaca',
            ],
            'Chincheros' => [
                ['Ahuayro', 'NA'],['Anco-huallo', 'Uripa'],'Chincheros','Cocharcas',
                ['El Porvenir', 'San Pedro de Huamburque'],'Huaccana',['Los Chankas', 'Río Blanco'],
                'Ocobamba','Ongoy','Ranracancha','Rocchacc','Uranmarca',
            ],
            'Cotabambas' => [
                'Challhuahuacho','Cotabambas','Coyllurqui','Haquira','Mara','Tambobamba',
            ],
            'Grau' => [
                'Chuquibambilla','Curasco','Curpahuasi',['Gamarra', 'Palpacachi'],'Huayllati',
                'Mamara',['Micaela Bastidas', 'Ayrihuanca'],'Pataypampa','Progreso','San Antonio',
                'Santa Rosa','Turpay','Vilcabamba',['Virundo', 'San Juan de Virundo'],
            ],
        ],
        'Arequipa' => [
            'Arequipa' => [
                ['Alto Selva Alegre', 'Selva Alegre'],'Arequipa','Cayma',
                ['Cerro Colorado', 'La Libertad'],'Characato','Chiguata','Jacobo Hunter',
                ['José Luis Bustamante y Rivero', 'Ciudad Satélite'],'La Joya','Mariano Melgar',
                'Miraflores','Mollebaya','Paucarpata','Pocsi',['Polobaya', 'Polobaya Grande'],
                'Quequeña','Sabandía','Sachaca','San Juan de Siguas',
                ['San Juan de Tarucani', 'Tarucani'],'Santa Isabel de Siguas',
                'Santa Rita de Siguas','Socabaya','Tiabaya','Uchumayo','Vítor','Yanahuara',
                'Yarabamba','Yura',
            ],
            'Camaná' => [
                'Camana',['Jose Maria Quimper', 'El Cardo'],
                ['Mariano Nicolas Valcarcel', 'Urasqui'],['Mariscal Caceres', 'San José'],
                ['Nicolas de Pierola', 'San Gregorio'],'Ocoña','Quilca',
                ['Samuel Pastor', 'La Pampa'],
            ],
            'Caravelí' => [
                'Acari','Atico','Atiquipa','Bella Union','Cahuacho','Caraveli','Chala',
                ['Chaparra', 'Achanizo'],['Huanuhuanu', 'Tocota'],'Jaqui','Lomas','Quicacha',
                'Yauca',
            ],
            'Castilla' => [
                'Andagua','Aplao','Ayo','Chachas','Chilcaymarca','Choco','Huancarqui','Machaguay',
                'Orcopampa','Pampacolca','Tipan','Uñon',['Uraca', 'Corire'],'Viraco',
            ],
            'Caylloma' => [
                'Achoma','Cabanaconde','Callalli','Caylloma','Chivay','Coporaque','Huambo','Huanca',
                'Ichupampa','Lari','Lluta','Maca','Madrigal',['Majes', 'El Pedregal'],
                'San Antonio de Chuca','Sibayo','Tapay','Tisco','Tuti','Yanque',
            ],
            'Condesuyos' => [
                'Andaray','Cayarani','Chichas','Chuquibamba','Iray',['Rio Grande', 'Iquipi'],
                'Salamanca','Yanaquihua',
            ],
            'Islay' => [
                'Cocachacra',['Dean Valdivia', 'La Curva'],['Islay', 'Islay (Matarani)'],'Mejia',
                'Mollendo','Punta de Bombon',
            ],
            'La Unión' => [
                'Alca','Charcana','Cotahuasi',['Huaynacotas', 'Taurisma'],['Pampamarca', 'Mungui'],
                'Puyca',['Quechualla', 'Velinga'],'Sayla','Tauria','Tomepampa','Toro',
            ],
        ],
        'Ayacucho' => [
            'Cangallo' => [
                'Cangallo','Chuschi',['Los Morochucos', 'Pampa - Cangallo'],
                ['Maria Parado de Bellido', 'Pomabamba'],'Paras','Totos',
            ],
            'Huamanga' => [
                'Acocro','Acos Vinchos',['Andres Avelino Caceres Dorregaray', 'Jardín'],'Ayacucho',
                'Carmen Alto','Chiara',['Jesus Nazareno', 'Las Nazarenas'],'Ocros','Pacaycasa',
                'Quinua',['San Jose de Ticllas', 'Ticllas'],'San Juan Bautista',
                ['Santiago de Pischa', 'San Pedro de Cachi'],'Socos','Tambillo','Vinchos',
            ],
            'Huanca Sancos' => [
                'Carapo','Sacsamarca',['Sancos', 'Huanca Sancos'],'Santiago de Lucanamarca',
            ],
            'Huanta' => [
                ['Ayahuanco', 'Viracochan'],'Canayre','Chaca','Huamanguilla','Huanta',
                ['Iguain', 'Macachacra'],'Llochegua','Luricocha',['Pucacolpa', '‎Huallhua'],
                ['Putis', 'NA'],['Santillana', 'San José De Secce'],'Sivia',
                ['Uchuraccay', 'Huaynacancha'],
            ],
            'La Mar' => [
                'Anchihuay',['Anco', 'Chiquintirca'],['Ayna', 'San Francisco'],'Chilcas','Chungui',
                ['Luis Carranza', 'Pampas'],['Ninabamba', 'NA'],'Oronccoy',['Patibamba', 'NA'],
                ['Rio Magdalena', 'NA'],['Samugari', 'Palmapampa'],'San Miguel','Santa Rosa',
                'Tambo',['Union Progreso', 'NA'],
            ],
            'Lucanas' => [
                'Aucara','Cabana',['Carmen Salcedo', 'Andamarca'],'Chaviña','Chipao','Huac-huas',
                'Laramate',['Leoncio Prado', 'Tambo Quemado'],'Llauta','Lucanas','Ocaña','Otoca',
                'Puquio','Saisa','San Cristobal','San Juan','San Pedro','San Pedro de Palco',
                'Sancos','Santa Ana de Huaycahuacho','Santa Lucia',
            ],
            'Parinacochas' => [
                'Chumpi','Coracora',['Coronel Castañeda', 'Aniso'],'Pacapausa','Pullo',
                ['Puyusca', 'Incuyo'],'San Francisco de Ravacayco','Upahuacho',
            ],
            'Paucar del Sara Sara' => [
                'Colta','Corculla','Lampa','Marcabamba','Oyolo','Pararca','Pausa',
                'San Javier de Alpabamba','San Jose de Ushua',['Sara Sara', 'Quilcata'],
            ],
            'Sucre' => [
                'Belen','Chalcos','Chilcayoc','Huacaña','Morcolla','Paico','Querobamba',
                'San Pedro de Larcay','San Salvador de Quije','Santiago de Paucaray','Soras',
            ],
            'Victor Fajardo' => [
                'Alcamenca','Apongo','Asquipata','Canaria','Cayara','Colca','Huamanquiquia',
                'Huancapi','Huancaraylla',['Huaya', 'San Pedro de Huaya'],'Sarhua','Vilcanchos',
            ],
            'Vilcas Huaman' => [
                'Accomarca','Carhuanca','Concepcion','Huambalpa',
                ['Independencia', 'Paccha Huallhua'],'Saurama','Vilcas Huaman','Vischongo',
            ],
        ],
        'Cajamarca' => [
            'Cajabamba' => [
                'Cachachi','Cajabamba',['Condebamba', 'Cauday'],['Sitacocha', 'Lluchubamba'],
            ],
            'Cajamarca' => [
                'Asuncion','Cajamarca','Chetilla','Cospan','Encañada','Jesus','Llacanora',
                'Los Baños del Inca','Magdalena','Matara','Namora','San Juan',
            ],
            'Celendin' => [
                'Celendin','Chumuch',['Cortegana', 'Chimuch (Cortegana)'],'Huasmin',
                ['Jorge Chavez', 'Lucmapampa'],['Jose Galvez', 'Huacapampa'],
                'La Libertad de Pallan',['Miguel Iglesias', 'Chalan'],'Oxamarca','Sorochuco',
                'Sucre','Utco',
            ],
            'Chota' => [
                'Anguia','Chadin','Chalamarca','Chiguirip','Chimban','Choropampa','Chota',
                'Cochabamba','Conchan','Huambos','Lajas','Llama','Miracosta','Paccha','Pion',
                'Querocoto',['San Juan de Licupis', 'Licupis'],'Tacabamba','Tocmoche',
            ],
            'Contumaza' => [
                'Chilete','Contumaza',['Cupisnique', 'Trinidad'],'Guzmango','San Benito',
                'Santa Cruz de Toledo',['Tantarica', 'Catan'],['Yonan', 'Tembladera'],
            ],
            'Cutervo' => [
                'Callayuc','Choros','Cujillo','Cutervo','La Ramada','Pimpingos','Querocotillo',
                'San Andres de Cutervo','San Juan de Cutervo','San Luis de Lucma','Santa Cruz',
                'Santo Domingo de la Capilla','Santo Tomas','Socota',
                ['Toribio Casanova', 'La Sacilia'],
            ],
            'Hualgayoc' => [
                'Bambamarca','Chugur','Hualgayoc',
            ],
            'Jaen' => [
                'Bellavista','Chontali','Colasay','Huabal','Jaen','Las Pirias','Pomahuaca','Pucara',
                'Sallique','San Felipe','San Jose del Alto','Santa Rosa',
            ],
            'San Ignacio' => [
                'Chirinos','Huarango','La Coipa','Namballe','San Ignacio','San Jose de Lourdes',
                'Tabaconas',
            ],
            'San Marcos' => [
                'Chancay',['Eduardo Villanueva', 'La Grama'],['Gregorio Pita', 'Paucamarca'],
                'Ichocan',['Jose Manuel Quiroz', 'Shirac'],['Jose Sabogal', 'Venecia'],
                ['Pedro Galvez', 'San Marcos'],
            ],
            'San Miguel' => [
                'Bolivar','Calquis','Catilluc','El Prado','La Florida','Llapa','Nanchoc','Niepos',
                'San Gregorio',['San Miguel', 'San Miguel de Pallaques'],'San Silvestre de Cochan',
                'Tongod',['Union Agua Blanca', 'Agua Blanca'],
            ],
            'San Pablo' => [
                'San Bernardino',['San Luis', 'San Luis Grande'],'San Pablo','Tumbaden',
            ],
            'Santa Cruz' => [
                'Andabamba','Catache','Chancaybaños','La Esperanza','Ninabamba','Pulan',
                ['Santa Cruz', 'Santa Cruz de Succhabamba'],'Saucepampa','Sexi','Uticyacu',
                'Yauyucan',
            ],
        ],
        'Callao' => [
            'Callao' => [
                'Bellavista','Callao','Carmen de la Legua Reynoso','La Perla','La Punta','Mi Perú',
                'Ventanilla',
            ],
        ],
        'Cusco' => [
            'Acomayo' => [
                'Acomayo','Acopia','Acos','Mosoc Llacta','Pomacanchi','Rondocan','Sangarara',
            ],
            'Anta' => [
                'Ancahuasi','Anta','Cachimayo','Chinchaypujio','Huarocondo','Limatambo','Mollepata',
                'Pucyura','Zurite',
            ],
            'Calca' => [
                'Calca','Coya','Lamay','Lares','Pisac','San Salvador','Taray',
                ['Yanatile', 'Quebrada Honda'],
            ],
            'Canas' => [
                'Checca',['Kunturkanki', 'El Descanso'],'Langui','Layo','Pampamarca','Quehue',
                ['Tupac Amaru', 'Tungasuca'],'Yanaoca',
            ],
            'Canchis' => [
                'Checacupe','Combapata','Marangani','Pitumarca','San Pablo','San Pedro','Sicuani',
                'Tinta',
            ],
            'Chumbivilcas' => [
                'Capacmarca','Chamaca','Colquemarca','Livitaca','Llusco','Quiñota','Santo Tomas',
                'Velille',
            ],
            'Cusco' => [
                'Ccorca','Cusco','Poroy','San Jeronimo','San Sebastian','Santiago','Saylla',
                'Wanchaq',
            ],
            'Espinar' => [
                ['Alto Pichigua', 'Accocunca'],'Condoroma','Coporaque',['Espinar', 'Yauri'],
                'Ocoruro',['Pallpata', 'Héctor Tejada'],'Pichigua','Suyckutambo',
            ],
            'La Convencion' => [
                ['Cielo Punco', 'NA'],'Echarate',['Huayopata', 'Ipal'],['Inkawasi', 'Amaybamba'],
                ['Kumpirushiato', 'NA'],['Manitea', 'NA'],'Maranura',['Megantoni', 'Camisea'],
                'Ocobamba','Pichari','Quellouno',['Quimbiri', 'Kimbiri'],
                ['Santa Ana', 'Quillabamba'],'Santa Teresa',['Union Asháninka', 'NA'],
                ['Vilcabamba', 'Lucma'],'Villa Kintiarina','Villa Virgen',
            ],
            'Paruro' => [
                'Accha','Ccapi','Colcha','Huanoquite','Omacha','Paccaritambo','Paruro','Pillpinto',
                'Yaurisque',
            ],
            'Paucartambo' => [
                'Caicay','Challabamba','Colquepata','Huancarani',['Kosñipata', 'Pillcopata'],
                'Paucartambo',
            ],
            'Quispicanchi' => [
                'Andahuaylillas',['Camanti', 'Quince Mil'],'Ccarhuayo','Ccatca','Cusipata','Huaro',
                'Lucre','Marcapata','Ocongate','Oropesa','Quiquijana','Urcos',
            ],
            'Urubamba' => [
                'Chinchero','Huayllabamba','Machupicchu','Maras','Ollantaytambo','Urubamba','Yucay',
            ],
        ],
        'Huancavelica' => [
            'Acobamba' => [
                'Acobamba','Andabamba','Anta','Caja','Marcas','Paucara','Pomacocha','Rosario',
            ],
            'Angaraes' => [
                'Anchonga','Callanmarca','Ccochaccasa','Chincho','Congalla','Huanca-huanca',
                'Huayllay Grande','Julcamarca','Lircay',['San Antonio de Antaparco', 'Antaparco'],
                'Santo Tomas de Pata','Secclla',
            ],
            'Castrovirreyna' => [
                'Arma','Aurahua','Capillas','Castrovirreyna','Chupamarca','Cocas','Huachos',
                'Huamatambo','Mollepampa','San Juan','Santa Ana','Tantara','Ticrapo',
            ],
            'Churcampa' => [
                ['Anco', 'La Esmeralda'],'Chinchihuasi','Churcampa',
                ['Cosme', 'Santa Clara de Cosme'],['El Carmen', 'Paucarbambilla'],'La Merced',
                'Locroja','Pachamarca','Paucarbamba',['San Miguel de Mayocc', 'Mayocc'],
                'San Pedro de Coris',
            ],
            'Huancavelica' => [
                'Acobambilla','Acoria','Ascension','Conayca','Cuenca','Huachocolpa','Huancavelica',
                'Huando','Huayllahuara','Izcuchaca','Laria','Manta','Mariscal Caceres','Moya',
                ['Nuevo Occoro', 'Occoro'],'Palca','Pilchaca','Vilca','Yauli',
            ],
            'Huaytara' => [
                'Ayavi','Cordova','Huayacundo Arma','Huaytara','Laramarca','Ocoyo','Pilpichaca',
                'Querco','Quito-arma',['San Antonio de Cusicancha', 'Cusicancha'],
                'San Francisco de Sangayaico',['San Isidro', 'San Juan de Huirpacancha'],
                'Santiago de Chocorvos','Santiago de Quirahuara','Santo Domingo de Capillas',
                'Tambo',
            ],
            'Tayacaja' => [
                'Acostambo','Acraquia','Ahuaycha','Andaymarca',['Cochabamba', 'NA'],'Colcabamba',
                ['Daniel Hernandez', 'Mariscal Cáceres'],'Huachocolpa','Huaribamba',
                ['Lambras', 'NA'],'Ñahuimpuquio','Pampas','Pazos','Pichos','Quichuas','Quishuar',
                ['Roble', 'Puerto San Antonio'],'Salcabamba','Salcahuasi','San Marcos de Rocchac',
                'Santiago de Tucuma','Surcubamba',['Tintay Puncu', 'Tintay'],
            ],
        ],
        'Huánuco' => [
            'Ambo' => [
                'Ambo','Cayna','Colpas','Conchamarca','Huacar',['San Francisco', 'Mosca'],
                'San Rafael','Tomay Kichwa',
            ],
            'Dos de Mayo' => [
                'Chuquis','La Union','Marias','Pachas','Quivilla','Ripan','Shunqui','Sillapata',
                'Yanas',
            ],
            'Huacaybamba' => [
                'Canchabamba','Cochabamba','Huacaybamba','Pinra',
            ],
            'Huamalies' => [
                'Arancay','Chavin de Pariarca','Jacas Grande','Jircan','Llata','Miraflores',
                'Monzon','Punchao','Puños','Singa','Tantamayo',
            ],
            'Huanuco' => [
                ['Amarilis', 'Paucarbamba'],['Chinchao', 'Acomayo'],'Churubamba','Huanuco','Margos',
                ['Pillco Marca', 'Cayhuayna'],['Quisqui', 'Huancapallac'],
                ['San Francisco de Cayran', 'Cayrán'],'San Pablo de Pillao',
                ['San Pedro de Chaulan', 'Chaulán'],'Santa Maria del Valle','Yacus','Yarumayo',
            ],
            'Lauricocha' => [
                'Baños','Jesus','Jivia','Queropalca','Rondos',['San Francisco de Asis', 'Huarin'],
                ['San Miguel de Cauri', 'Cauri'],
            ],
            'Leoncio Prado' => [
                'Castillo Grande',['Daniel Alomias Robles', 'Daniel Alomía Robles (Pumahuasi)'],
                'Hermilio Valdizan',['Jose Crespo y Castillo', 'Aucayacu'],'Luyando',
                ['Mariano Damaso Beraun', 'Las Palmas'],'Pucayacu','Pueblo Nuevo',
                ['Rupa-rupa', 'Tingo María'],['Santo Domingo de Anda', 'Pacae'],
            ],
            'Marañon' => [
                ['Cholon', 'San Pedro de Chonta'],'Huacrachuco','La Morada','San Buenaventura',
                'Santa Rosa de Alto Yanajanca',
            ],
            'Pachitea' => [
                'Chaglla','Molino','Panao',['Umari', 'Umari (Tambillo)'],
            ],
            'Puerto Inca' => [
                'Codo del Pozuzo','Honoria','Puerto Inca','Tournavista','Yuyapichis',
            ],
            'Yarowilca' => [
                ['Aparicio Pomares', 'Chupan'],'Cahuac','Chacabamba','Chavinillo','Choras',
                ['Jacas Chico', 'San Cristóbal de Jacas Chico'],'Obas','Pampamarca',
            ],
        ],
        'Ica' => [
            'Chincha' => [
                'Alto Laran','Chavin','Chincha Alta','Chincha Baja','El Carmen',
                ['Grocio Prado', 'San Pedro'],'Pueblo Nuevo','San Juan de Yanac',
                'San Pedro de Huacarpana','Sunampe','Tambo de Mora',
            ],
            'Ica' => [
                'Ica','La Tinguiña','Los Aquijes','Ocucaje',['Pachacutec', 'Pampa de Tate'],
                'Parcona','Pueblo Nuevo',['Salas', 'Guadalupe'],'San Jose de los Molinos',
                'San Juan Bautista','Santiago','Subtanjalla',['Tate', 'Tate de la Capilla'],
                ['Yauca del Rosario', 'Curis'],
            ],
            'Nazca' => [
                'Changuillo','El Ingenio',['Marcona', 'San Juan'],['Nazca', 'Nasca'],'Vista Alegre',
            ],
            'Palpa' => [
                'Llipata','Palpa','Rio Grande','Santa Cruz','Tibillo',
            ],
            'Pisco' => [
                'Huancano','Humay','Independencia','Paracas','Pisco','San Andres','San Clemente',
                ['Tupac Amaru Inca', 'Túpac Amaru'],
            ],
        ],
        'Junín' => [
            'Chanchamayo' => [
                ['Chanchamayo', 'La Merced'],'Perene',['Pichanaqui', 'Bajo Pichanaqui'],
                'San Luis de Shuaro','San Ramon',['Vitoc', 'Pucará'],
            ],
            'Chupaca' => [
                'Ahuac','Chongos Bajo','Chupaca','Huachac','Huamancaca Chico',
                ['San Juan de Jarpa', 'Jarpa'],['San Juan de Yscos', 'Iscos'],'Tres de Diciembre',
                'Yanacancha',
            ],
            'Concepcion' => [
                'Aco','Andamarca','Chambara','Cochas','Comas','Concepcion',
                ['Heroinas Toledo', 'San Antonio de Ocopa'],['Manzanares', 'San Miguel'],
                ['Mariscal Castilla', 'Mucllo'],'Matahuasi','Mito',
                ['Nueve de Julio', 'Santo Domingo del Prado'],'Orcotuna','San Jose de Quero',
                ['Santa Rosa de Ocopa', 'Santa Rosa'],
            ],
            'Huancayo' => [
                'Carhuacallanga','Chacapampa','Chicche','Chilca','Chongos Alto','Chupuro','Colca',
                'Cullhuas','El Tambo','Huacrapuquio','Hualhuas','Huancan','Huancayo','Huasicancha',
                'Huayucachi','Ingenio','Pariahuanca','Pilcomayo','Pucara','Quichuay','Quilcas',
                'San Agustin','San Jeronimo de Tunan','Saño','Santo Domingo de Acobamba',
                'Sapallanga','Sicaya','Viques',
            ],
            'Jauja' => [
                'Acolla','Apata','Ataura','Canchayllo',['Curicaca', 'El Rosario'],
                ['El Mantaro', 'Pucucho'],'Huamali','Huaripampa','Huertas','Janjaillo','Jauja',
                'Julcan',['Leonor Ordoñez', 'Huancani'],'Llocllapampa','Marco','Masma',
                'Masma Chicche','Molinos','Monobamba','Muqui','Muquiyauyo','Paca','Paccha','Pancan',
                'Parco','Pomacancha','Ricran','San Lorenzo','San Pedro de Chunan','Sausa','Sincos',
                ['Tunan Marca', 'Concho'],'Yauli','Yauyos',
            ],
            'Junin' => [
                'Carhuamayo','Junin','Ondores','Ulcumayo',
            ],
            'Satipo' => [
                'Coviriali','Llaylla','Mazamari',['Pampa Hermosa', 'Mariposa'],
                ['Pangoa', 'San Martín de Pangoa'],'Rio Negro',['Rio Tambo', 'Puerto Ocopa'],
                'Satipo',['Vizcatan del Ene', 'San Miguel del Ene'],
            ],
            'Tarma' => [
                'Acobamba','Huaricolca','Huasahuasi',['La Union', 'Leticia'],'Palca','Palcamayo',
                'San Pedro de Cajas','Tapo','Tarma',
            ],
            'Yauli' => [
                'Chacapalpa','Huay-huay','La Oroya','Marcapomacocha',
                ['Morococha', 'Nueva Morococha'],'Paccha','Santa Barbara de Carhuacayan',
                'Santa Rosa de Sacco','Suitucancha','Yauli',
            ],
        ],
        'La Libertad' => [
            'Ascope' => [
                'Ascope','Casa Grande','Chicama','Chocope','Magdalena de Cao','Paijan',
                ['Razuri', 'Puerto de Malabrigo'],'Santiago de Cao',
            ],
            'Bolivar' => [
                'Bambamarca','Bolivar','Condormarca','Longotea','Uchumarca','Ucuncha',
            ],
            'Chepen' => [
                'Chepen','Pacanga','Pueblo Nuevo',
            ],
            'Gran Chimu' => [
                'Cascas','Lucma','Marmot','Sayapullo',
            ],
            'Julcan' => [
                'Calamarca','Carabamba','Huaso','Julcan',
            ],
            'Otuzco' => [
                'Agallpampa','Charat','Huaranchal','La Cuesta','Mache','Otuzco','Paranday','Salpo',
                'Sinsicap','Usquil',
            ],
            'Pacasmayo' => [
                'Guadalupe','Jequetepeque','Pacasmayo','San Jose','San Pedro de Lloc',
            ],
            'Pataz' => [
                'Buldibuyo','Chillia','Huancaspata','Huaylillas','Huayo','Ongon','Parcoy','Pataz',
                'Pias',['Santiago de Challas', 'Challas'],'Taurija','Tayabamba','Urpay',
            ],
            'Sanchez Carrion' => [
                'Chugay',['Cochorco', 'Aricapampa'],'Curgos','Huamachuco','Marcabal','Sanagoran',
                'Sarin','Sartimbamba',
            ],
            'Santiago de Chuco' => [
                'Angasmarca','Cachicadan','Mollebamba','Mollepata','Quiruvilca',
                'Santa Cruz de Chuca','Santiago de Chuco','Sitabamba',
            ],
            'Trujillo' => [
                'El Porvenir','Florencia de Mora','Huanchaco','La Esperanza','Laredo','Moche',
                'Poroto','Salaverry','Simbal','Trujillo',['Victor Larco Herrera', 'Buenos Aires'],
            ],
            'Viru' => [
                'Chao','Guadalupito','Viru',
            ],
        ],
        'Lambayeque' => [
            'Chiclayo' => [
                'Cayalti','Chiclayo','Chongoyape','Eten','Eten Puerto',
                ['Jose Leonardo Ortiz', 'San Carlos'],'La Victoria',['Lagunas', 'Mocupe'],'Monsefu',
                'Nueva Arica','Oyotun','Patapo','Picsi','Pimentel','Pomalca','Pucala','Reque',
                'Saña','Santa Rosa','Tuman',
            ],
            'Ferreñafe' => [
                'Cañaris','Ferreñafe','Incahuasi','Manuel Antonio Mesones Muro','Pitipo',
                'Pueblo Nuevo',
            ],
            'Lambayeque' => [
                'Chochope','Illimo','Jayanca','Lambayeque','Mochumi','Morrope','Motupe','Olmos',
                'Pacora','Salas','San Jose','Tucume',
            ],
        ],
        'Lima' => [
            'Barranca' => [
                'Barranca','Paramonga','Pativilca','Supe','Supe Puerto',
            ],
            'Cajatambo' => [
                'Cajatambo','Copa','Gorgor','Huancapon','Manas',
            ],
            'Cañete' => [
                'Asia','Calango','Cerro Azul','Chilca','Coayllo','Imperial','Lunahuana','Mala',
                'Nuevo Imperial','Pacaran','Quilmana','San Antonio','San Luis',
                'San Vicente de Cañete','Santa Cruz de Flores','Zuñiga',
            ],
            'Canta' => [
                'Arahuay','Canta','Huamantanga','Huaros','Lachaqui','San Buenaventura',
                ['Santa Rosa de Quives', 'Yangas'],
            ],
            'Huaral' => [
                ['Atavillos Alto', 'Pirca'],['Atavillos Bajo', 'San Agustín de Huayopampa'],
                'Aucallama','Chancay','Huaral','Ihuari','Lampian','Pacaraos',
                ['San Miguel de Acos', 'Acos'],'Santa Cruz de Andamarca','Sumbilca',
                ['Veintisiete de Noviembre', 'Carac'],
            ],
            'Huarochirí' => [
                'Antioquia','Callahuanca','Carampoma','Chicla',
                ['Cuenca', 'San José de Los Chorrillos'],
                ['Huachupampa', 'San Lorenzo de Huachupampa'],'Huanza','Huarochiri','Lahuaytambo',
                'Langa','Laraos','Mariatana','Matucana','Ricardo Palma','San Andres de Tupicocha',
                ['San Antonio', 'Chaclla'],'San Bartolome','San Damian','San Juan de Iris',
                'San Juan de Tantaranche','San Lorenzo de Quinti','San Mateo',
                ['San Mateo de Otao', 'San Juan de Lanca'],'San Pedro de Casta',
                ['San Pedro de Huancayre', 'San Pedro'],'Sangallaya',
                ['Santa Cruz de Cocachacra', 'Cocachacra'],'Santa Eulalia','Santiago de Anchucaya',
                'Santiago de Tuna','Santo Domingo de los Olleros','Surco',
            ],
            'Huaura' => [
                'Ambar','Caleta de Carquin',['Checras', 'Maray'],'Huacho','Hualmay','Huaura',
                ['Leoncio Prado', 'Santa Cruz'],'Paccho',['Santa Leonor', 'Jucul'],
                ['Santa Maria', 'Cruz Blanca'],'Sayan','Vegueta',
            ],
            'Lima' => [
                'Ancón',['Ate', 'Vitarte'],'Barranco','Breña','Carabayllo','Chaclacayo',
                'Chorrillos','Cieneguilla',['Comas', 'La Libertad'],'El Agustino','Independencia',
                'Jesús María','La Molina','La Victoria','Lima','Lince',
                ['Los Olivos', 'Las Palmeras'],['Lurigancho', 'Chosica'],'Lurín',
                'Magdalena del Mar','Miraflores','Pachacámac','Pucusana','Pueblo Libre',
                'Puente Piedra','Punta Hermosa','Punta Negra','Rímac','San Bartolo',
                ['San Borja', 'San Francisco de Borja'],'San Isidro','San Juan de Lurigancho',
                ['San Juan de Miraflores', 'Ciudad de Dios'],'San Luis',
                ['San Martín de Porres', 'Barrio Obrero Industrial'],'San Miguel',
                ['Santa Anita', 'Santa Anita - Los Ficus'],['Santa María de Huachipa', 'NA'],
                'Santa María del Mar','Santa Rosa','Santiago de Surco','Surquillo',
                'Villa El Salvador','Villa María del Triunfo',
            ],
            'Oyón' => [
                'Andajes','Caujul','Cochamarca','Navan','Oyon',['Pachangara', 'Churin'],
            ],
            'Yauyos' => [
                'Alis',['Ayauca', 'Allauca'],'Ayaviri','Azangaro','Cacra','Carania','Catahuasi',
                'Chocos','Cochas','Colonia','Hongos','Huampara','Huancaya','Huañec','Huangascar',
                'Huantan','Laraos','Lincha','Madean','Miraflores','Omas',
                ['Putinza', 'San Lorenzo de Putinza'],'Quinches','Quinocay','San Joaquin',
                'San Pedro de Pilas','Tanta','Tauripampa','Tomas','Tupe','Viñac','Vitis','Yauyos',
            ],
        ],
        'Loreto' => [
            'Alto Amazonas' => [
                'Balsapuerto','Jeberos','Lagunas','Santa Cruz',
                ['Teniente Cesar Lopez Rojas', 'Shucushuyacu'],'Yurimaguas',
            ],
            'Datem del Marañon' => [
                ['Andoas', 'Alianza Cristiana'],['Barranca', 'San Lorenzo'],
                ['Cahuapanas', 'Santa Maria de Cahuapanas'],['Manseriche', 'Saramiriza'],
                ['Morona', 'Puerto Alegría'],['Pastaza', 'Ullpayacu'],
            ],
            'Loreto' => [
                'Nauta','Parinari',['Tigre', 'Intutu'],['Trompeteros', 'Villa Trompeteros'],
                ['Urarinas', 'Concordia'],
            ],
            'Mariscal Ramon Castilla' => [
                'Pebas',['Ramon Castilla', 'Caballococha'],['San Pablo', 'San Pablo de Loreto'],
                ['Yavari', 'Amelia'],
            ],
            'Maynas' => [
                ['Alto Nanay', 'Santa María de Nanay'],'Belen',['Fernando Lores', 'Tamshiyacu'],
                'Indiana','Iquitos',['Las Amazonas', 'Francisco de Orellana'],'Mazan',
                ['Napo', 'Santa Clotilde'],'Punchana',['Putumayo', 'NA'],
                ['San Juan Bautista', 'San Juan'],['Teniente Manuel Clavero', 'NA'],
                ['Torres Causana', 'Pantoja'],
            ],
            'Putumayo' => [
                ['Putumayo', 'San Antonio del Estrecho'],['Rosa Panduro', 'Santa Mercedes'],
                ['Teniente Manuel Clavero', 'Soplin Vargas'],['Yaguas', 'Remanso'],
            ],
            'Requena' => [
                ['Alto Tapiche', 'Santa Elena'],['Capelo', 'Flor de Punga'],
                ['Emilio San Martin', 'Tamanco'],'Jenaro Herrera',['Maquia', 'Santa Isabel'],
                ['Puinahua', 'Bretaña'],'Requena',['Saquena', 'Bagazan'],
                ['Soplin', 'Nueva Alejandría (Curinga)'],['Tapiche', 'Iberia'],
                ['Yaquerana', 'Angamos'],
            ],
            'Ucayali' => [
                'Contamana','Inahuaya',['Padre Marquez', 'Tiruntan'],'Pampa Hermosa',
                ['Sarayacu', 'Dos de Mayo'],['Vargas Guerra', 'Orellana'],
            ],
        ],
        'Madre de Dios' => [
            'Manu' => [
                ['Fitzcarrald', 'Boca Manu'],'Huepetuhe',['Madre de Dios', 'Boca Colorado'],
                ['Manu', 'Salvación'],
            ],
            'Tahuamanu' => [
                'Iberia','Iñapari',['Tahuamanu', 'San Lorenzo'],
            ],
            'Tambopata' => [
                ['Inambari', 'Mazuko'],['Laberinto', 'Puerto Rosario de Laberinto'],
                ['Las Piedras', 'Las Piedras (Planchón)'],['Tambopata', 'Puerto Maldonado'],
            ],
        ],
        'Moquegua' => [
            'General Sanchez Cerro' => [
                'Chojata','Coalaque','Ichuña','La Capilla','Lloque','Matalaque','Omate','Puquina',
                'Quinistaquillas','Ubinas','Yunga',
            ],
            'Ilo' => [
                'El Algarrobal','Ilo',['Pacocha', 'Pueblo Nuevo'],
            ],
            'Mariscal Nieto' => [
                'Carumas','Cuchumbaya','Moquegua','Samegua',['San Antonio', 'NA'],
                ['San Cristobal', 'Calacoa'],'Torata',
            ],
        ],
        'Pasco' => [
            'Daniel Alcides Carrion' => [
                'Chacayan','Goyllarisquizga','Paucar','San Pedro de Pillao','Santa Ana de Tusi',
                'Tapuc','Vilcabamba','Yanahuanca',
            ],
            'Oxapampa' => [
                'Chontabamba','Constitucion','Huancabamba','Oxapampa',['Palcazu', 'Iscozacin'],
                'Pozuzo','Puerto Bermudez','Villa Rica',
            ],
            'Pasco' => [
                ['Chaupimarca', 'Cerro de Pasco'],'Huachon','Huariaca','Huayllay','Ninacaca',
                'Pallanchacra','Paucartambo',['San Francisco de Asis de Yarusyacan', 'Yarusyacán'],
                ['Simon Bolivar', 'San Antonio de Rancas'],'Ticlacayan',
                ['Tinyahuarco', 'Tinyahuarco (Smelter)'],'Vicco','Yanacancha',
            ],
        ],
        'Piura' => [
            'Ayabaca' => [
                'Ayabaca','Frias','Jilili','Lagunas','Montero','Pacaipampa','Paimas','Sapillica',
                'Sicchez','Suyo',
            ],
            'Huancabamba' => [
                'Canchaque',['El Carmen de la Frontera', 'Sapalache'],'Huancabamba','Huarmaca',
                ['Lalaquiz', 'Tunal'],'San Miguel de El Faique','Sondor','Sondorillo',
            ],
            'Morropon' => [
                'Buenos Aires','Chalaco','Chulucanas','La Matanza','Morropon','Salitral',
                ['San Juan de Bigote', 'Bigote'],['Santa Catalina de Mossa', 'Paltashaco'],
                'Santo Domingo','Yamango',
            ],
            'Paita' => [
                'Amotape','Arenal',['Colan', 'San Lucas (Pueblo Nuevo de Colán)'],'La Huaca',
                'Paita','Tamarindo',['Vichayal', 'San Felipe de Vichayal'],
            ],
            'Piura' => [
                'Castilla','Catacaos',['Cura Mori', 'Cucungara'],['El Tallan', 'Sinchao'],
                'La Arena','La Union','Las Lomas','Piura','Tambo Grande',
                ['Veintiseis de Octubre', 'San Martín'],
            ],
            'Sechura' => [
                ['Bellavista de la Union', 'Bellavista'],'Bernal',
                ['Cristo Nos Valga', 'San Cristo'],['Rinconada Llicuar', 'Dos Pueblos'],'Sechura',
                'Vice',
            ],
            'Sullana' => [
                'Bellavista',['Ignacio Escudero', 'San Jacinto'],'Lancones','Marcavelica',
                ['Miguel Checa', 'Sojo'],'Querecotillo','Salitral','Sullana',
            ],
            'Talara' => [
                'El Alto',['La Brea', 'Negritos'],'Lobitos','Los Organos','Mancora',
                ['Pariñas', 'Talara'],
            ],
        ],
        'Puno' => [
            'Azangaro' => [
                'Achaya','Arapa','Asillo','Azangaro','Caminaca','Chupa',
                ['Jose Domingo Choquehuanca', 'Estación de Pucará'],'Muñani','Potoni','Saman',
                'San Anton','San Jose','San Juan de Salinas','Santiago de Pupuja','Tirapata',
            ],
            'Carabaya' => [
                'Ajoyani','Ayapata','Coasa','Corani','Crucero','Ituata','Macusani','Ollachea',
                ['San Gaban', 'Lanlacuni Bajo'],'Usicayos',
            ],
            'Chucuito' => [
                'Desaguadero','Huacullani','Juli','Kelluyo','Pisacoma','Pomata','Zepita',
            ],
            'El Collao' => [
                'Capazo','Conduriri','Ilave','Pilcuyo',['Santa Rosa', 'Mazo Cruz'],
            ],
            'Huancane' => [
                'Cojata','Huancane','Huatasani','Inchupalla','Pusi','Rosaspata','Taraco',
                'Vilque Chico',
            ],
            'Lampa' => [
                'Cabanilla','Calapuja','Lampa','Nicasio','Ocuviri','Palca','Paratia','Pucara',
                'Santa Lucia','Vilavila',
            ],
            'Melgar' => [
                'Antauta','Ayaviri','Cupi','Llalli','Macari','Nuñoa','Orurillo','Santa Rosa',
                'Umachiri',
            ],
            'Moho' => [
                'Conima','Huayrapata','Moho','Tilali',
            ],
            'Puno' => [
                'Acora','Amantani','Atuncolla','Capachica','Chucuito','Coata','Huata','Mañazo',
                'Paucarcolla',['Pichacani', 'Laraqueri'],'Plateria','Puno',
                ['San Antonio', 'San Antonio de Esquilache'],'Tiquillaca','Vilque',
            ],
            'San Antonio de Putina' => [
                'Ananea',['Pedro Vilca Apaza', 'Ayrampuni'],'Putina','Quilcapuncu','Sina',
            ],
            'San Roman' => [
                'Cabana',['Cabanillas', 'Deustua'],'Caracoto','Juliaca','San Miguel',
            ],
            'Sandia' => [
                ['Alto Inambari', 'Massiapo'],'Cuyocuyo','Limbani','Patambuco','Phara','Quiaca',
                'San Juan del Oro',['San Pedro de Putina Punco', 'Putina Punco'],'Sandia',
                'Yanahuaya',
            ],
            'Yunguyo' => [
                'Anapia','Copani',['Cuturapi', 'San Juan de Cuturapi'],
                ['Ollaraya', 'San Miguel de Ollaraya'],'Tinicachi',['Unicachi', 'Marcaja'],
                'Yunguyo',
            ],
        ],
        'San Martín' => [
            'Bellavista' => [
                ['Alto Biavo', 'Cuzco'],['Bajo Biavo', 'Nuevo Lima'],'Bellavista',
                ['Huallaga', 'Ledoy'],'San Pablo','San Rafael',
            ],
            'El Dorado' => [
                'Agua Blanca','San Jose de Sisa','San Martin','Santa Rosa','Shatoja',
            ],
            'Huallaga' => [
                ['Alto Saposoa', 'Pasarraya'],'El Eslabon','Piscoyacu','Sacanche','Saposoa',
                'Tingo de Saposoa',
            ],
            'Lamas' => [
                ['Alonso de Alvarado', 'Roque'],'Barranquita',['Caynarachi', 'Pongo de Caynarachi'],
                'Cuñumbuqui','Lamas','Pinto Recodo','Rumisapa','San Roque de Cumbaza','Shanao',
                'Tabalosos','Zapatero',
            ],
            'Mariscal Caceres' => [
                'Campanilla','Huicungo','Juanjui','Pachiza','Pajarillo',
            ],
            'Moyobamba' => [
                'Calzada','Habana','Jepelacio','Moyobamba','Soritor','Yantalo',
            ],
            'Picota' => [
                'Buenos Aires','Caspisapa','Picota','Pilluana','Pucacaca',
                ['San Cristobal', 'Puerto Rico'],['San Hilarion', 'San Cristóbal de Sisa'],
                'Shamboyacu','Tingo de Ponasa','Tres Unidos',
            ],
            'Rioja' => [
                ['Awajun', 'Bajo Naranjillo'],
                ['Elias Soplin Vargas', 'Segunda Jerusalen-Azunguillo'],'Nueva Cajamarca',
                ['Pardo Miguel', 'Naranjos'],'Posic','Rioja','San Fernando','Yorongos','Yuracyacu',
            ],
            'San Martin' => [
                ['Alberto Leveau', 'Utcurarca'],'Cacatachi','Chazuta',['Chipurana', 'Navarro'],
                ['El Porvenir', 'Pelejo'],'Huimbayoc','Juan Guerra',
                ['La Banda de Shilcayo', 'La Banda'],'Morales','Papaplaya','San Antonio','Sauce',
                'Shapaja','Tarapoto',
            ],
            'Tocache' => [
                'Nuevo Progreso','Polvora',['Santa Lucia', 'NA'],['Shunte', 'Tambo de Paja'],
                'Tocache','Uchiza',
            ],
        ],
        'Tacna' => [
            'Candarave' => [
                'Cairani',['Camilaca', 'Alto Camilaca'],'Candarave','Curibaya','Huanuara',
                'Quilahuani',
            ],
            'Jorge Basadre' => [
                'Ilabaya','Ite','Locumba',
            ],
            'Tacna' => [
                ['Alto de la Alianza', 'La Esperanza'],'Calana','Ciudad Nueva',
                ['Coronel Gregorio Albarracin Lanchipa', 'Alfonso Ugarte'],
                ['Inclan', 'Sama Grande'],['La Yarada los Palos', 'Los Palos'],'Pachia','Palca',
                'Pocollay',['Sama', 'Las Yaras'],'Tacna',
            ],
            'Tarata' => [
                'Estique','Estique-pampa',['Heroes Albarracin Chucatamani', 'Chucatamani'],
                'Sitajara','Susapaya','Tarata','Tarucachi','Ticaco',
            ],
        ],
        'Tumbes' => [
            'Contralmirante Villar' => [
                ['Canoas de Punta Sal', 'Cancas'],['Casitas', 'Cañaveral'],'Zorritos',
            ],
            'Tumbes' => [
                ['Corrales', 'San Pedro de Los Incas'],['La Cruz', 'Caleta Cruz'],
                'Pampas de Hospital','San Jacinto','San Juan de la Virgen','Tumbes',
            ],
            'Zarumilla' => [
                'Aguas Verdes','Matapalo','Papayal','Zarumilla',
            ],
        ],
        'Ucayali' => [
            'Atalaya' => [
                ['Raymondi', 'Atalaya'],'Sepahua',['Tahuania', 'Bolognesi'],['Yurua', 'Breu'],
            ],
            'Coronel Portillo' => [
                ['Calleria', 'Pucallpa'],'Campoverde','Iparia',['Manantay', 'San Fernando'],
                'Masisea','Nueva Requena',['Yarinacocha', 'Puerto Callao'],
            ],
            'Padre Abad' => [
                'Alexander Von Humboldt',['Boqueron', 'NA'],'Curimana',['Huipoca', 'NA'],
                ['Irazola', 'San Alejandro'],['Neshuya', 'Monte Alegre'],['Padre Abad', 'Aguaytía'],
            ],
            'Purus' => [
                ['Purus', 'Esperanza'],
            ],
        ],
    ];
}
