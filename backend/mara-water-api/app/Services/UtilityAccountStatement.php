<?php

namespace App\Services;

/** AfriGig utility account 4321343, statement pulled 22/11/2026 00:10. Rows are newest first. */
class UtilityAccountStatement
{
    public const AS_OF = '2026-11-22 00:10:21';
    public const SHORT_CODE = '4321343';
    public const HOLDER = 'AFRIGIG PAYHUB';
    public const OPENING_SHOWN = 10.0;

    /** @return list<array{receipt:string,date:string,time:string,paid_in:float,withdrawn:float,balance:float,party:string,account:string}> */
    public static function rows(): array
    {
        return [
            ['receipt'=>'UKKTE6IWUQ','date'=>'2026-11-20','time'=>'23:28:14','paid_in'=>0.00,'withdrawn'=>10000.00,'balance'=>101120.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UKKM62ZQQ9','date'=>'2026-11-20','time'=>'21:32:19','paid_in'=>20300.00,'withdrawn'=>0.00,'balance'=>111120.00,'party'=>'Dennis Kipyego','account'=>'Mara'],
            ['receipt'=>'UKKRD3AARO','date'=>'2026-11-20','time'=>'17:49:40','paid_in'=>1910.00,'withdrawn'=>0.00,'balance'=>90820.00,'party'=>'John **** Ondito','account'=>'Mara'],
            ['receipt'=>'UKKK035QMH','date'=>'2026-11-20','time'=>'12:02:39','paid_in'=>3700.00,'withdrawn'=>0.00,'balance'=>88910.00,'party'=>'FREDRICK **** OONGO','account'=>'Mara'],
            ['receipt'=>'UKK3N36PA7','date'=>'2026-11-20','time'=>'08:31:01','paid_in'=>1480.00,'withdrawn'=>0.00,'balance'=>85210.00,'party'=>'ANNE **** ORINDA','account'=>'Mara'],
            ['receipt'=>'UKJM62V2JI','date'=>'2026-11-19','time'=>'21:54:38','paid_in'=>5880.00,'withdrawn'=>0.00,'balance'=>83730.00,'party'=>'Dennis Kipyego','account'=>'mara'],
            ['receipt'=>'UKJRD359K2','date'=>'2026-11-19','time'=>'16:46:37','paid_in'=>1280.00,'withdrawn'=>0.00,'balance'=>77850.00,'party'=>'John **** Ondito','account'=>'mara'],
            ['receipt'=>'UKICC2SVGQ','date'=>'2026-11-18','time'=>'16:31:31','paid_in'=>370.00,'withdrawn'=>0.00,'balance'=>76570.00,'party'=>'DANCAN OMONDI','account'=>'mara'],
            ['receipt'=>'UKIU77T29Z','date'=>'2026-11-18','time'=>'14:27:27','paid_in'=>70.00,'withdrawn'=>0.00,'balance'=>76200.00,'party'=>'Amos Mbela Odongo','account'=>'mara'],
            ['receipt'=>'UKITE6EHLE','date'=>'2026-11-18','time'=>'13:58:47','paid_in'=>0.00,'withdrawn'=>3000.00,'balance'=>76130.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UKHCF28EDD','date'=>'2026-11-17','time'=>'01:10:46','paid_in'=>4000.00,'withdrawn'=>0.00,'balance'=>79130.00,'party'=>'akoko **** Alex','account'=>'Nyatike'],
            ['receipt'=>'UKGLZ24PN7','date'=>'2026-11-16','time'=>'13:58:25','paid_in'=>400.00,'withdrawn'=>0.00,'balance'=>75130.00,'party'=>'denish **** opar','account'=>'mara opar'],
            ['receipt'=>'UKG1V2JK80','date'=>'2026-11-16','time'=>'11:54:53','paid_in'=>740.00,'withdrawn'=>0.00,'balance'=>74730.00,'party'=>'Alice **** Akeno','account'=>'MARA'],
            ['receipt'=>'UKDCF1SWN7','date'=>'2026-11-13','time'=>'17:57:40','paid_in'=>3560.00,'withdrawn'=>0.00,'balance'=>73990.00,'party'=>'akoko **** Alex','account'=>'Matanda'],
            ['receipt'=>'UKDEU2F6WT','date'=>'2026-11-13','time'=>'13:59:05','paid_in'=>200.00,'withdrawn'=>0.00,'balance'=>70430.00,'party'=>'DIANA **** AUDI','account'=>'MARA DIANA'],
            ['receipt'=>'UKD1Q2TRBT','date'=>'2026-11-13','time'=>'11:46:35','paid_in'=>400.00,'withdrawn'=>0.00,'balance'=>70230.00,'party'=>'CLINTON **** NGESO','account'=>'mara'],
            ['receipt'=>'UKBBN28CMM','date'=>'2026-11-11','time'=>'19:08:47','paid_in'=>11280.00,'withdrawn'=>0.00,'balance'=>69830.00,'party'=>'Abonyo **** Wenwa','account'=>'Mara-Nyatike'],
            ['receipt'=>'UKBCF1JJID','date'=>'2026-11-11','time'=>'17:19:57','paid_in'=>15300.00,'withdrawn'=>0.00,'balance'=>58550.00,'party'=>'akoko **** Alex','account'=>'Nyatike'],
            ['receipt'=>'UKA7Q22MKL','date'=>'2026-11-10','time'=>'23:43:20','paid_in'=>3165.00,'withdrawn'=>0.00,'balance'=>43250.00,'party'=>'Santos **** omollo','account'=>'Mara Cathy'],
            ['receipt'=>'UKA7Q22L0O','date'=>'2026-11-10','time'=>'22:54:57','paid_in'=>1266.00,'withdrawn'=>0.00,'balance'=>40085.00,'party'=>'Santos **** omollo','account'=>'Mara Cathy'],
            ['receipt'=>'UKACC1RF63','date'=>'2026-11-10','time'=>'15:05:28','paid_in'=>1780.00,'withdrawn'=>0.00,'balance'=>38819.00,'party'=>'DANCAN OMONDI','account'=>'mara'],
            ['receipt'=>'UKAL010T87','date'=>'2026-11-10','time'=>'14:20:45','paid_in'=>1460.00,'withdrawn'=>0.00,'balance'=>37039.00,'party'=>'ERICK **** MIKURA','account'=>'MARA'],
            ['receipt'=>'UKAL31W2MJ','date'=>'2026-11-10','time'=>'10:44:34','paid_in'=>19610.00,'withdrawn'=>0.00,'balance'=>35579.00,'party'=>'Emmaculate **** Okelo','account'=>'mara'],
            ['receipt'=>'UKACC1Q478','date'=>'2026-11-10','time'=>'09:03:57','paid_in'=>23.00,'withdrawn'=>0.00,'balance'=>15969.00,'party'=>'DANCAN OMONDI','account'=>'mara masara'],
            ['receipt'=>'UKACC1Q1AU','date'=>'2026-11-10','time'=>'09:02:41','paid_in'=>6900.00,'withdrawn'=>0.00,'balance'=>15946.00,'party'=>'DANCAN OMONDI','account'=>'Mara masara'],
            ['receipt'=>'UK9TE5ZG8Q','date'=>'2026-11-09','time'=>'23:54:04','paid_in'=>0.00,'withdrawn'=>4000.00,'balance'=>9046.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UK9TE5ZFCS','date'=>'2026-11-09','time'=>'23:35:59','paid_in'=>0.00,'withdrawn'=>30000.00,'balance'=>13046.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UK9CF1CA6N','date'=>'2026-11-09','time'=>'23:19:22','paid_in'=>8950.00,'withdrawn'=>0.00,'balance'=>43046.00,'party'=>'akoko **** Alex','account'=>'Mara MASARA'],
            ['receipt'=>'UK9BN1WD8W','date'=>'2026-11-09','time'=>'09:40:40','paid_in'=>380.00,'withdrawn'=>0.00,'balance'=>34096.00,'party'=>'Abonyo **** Wenwa','account'=>'MARA-SAGCZDH'],
            ['receipt'=>'UK9TE5XZXH','date'=>'2026-11-09','time'=>'07:59:43','paid_in'=>0.00,'withdrawn'=>15000.00,'balance'=>33716.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UK7CF137LP','date'=>'2026-11-07','time'=>'22:02:04','paid_in'=>2160.00,'withdrawn'=>0.00,'balance'=>48716.00,'party'=>'akoko **** Alex','account'=>'MARA-MSA-01A'],
            ['receipt'=>'UK71A0WVGL','date'=>'2026-11-07','time'=>'19:40:01','paid_in'=>7800.00,'withdrawn'=>0.00,'balance'=>46556.00,'party'=>'DANCUN OJODEH','account'=>'MARA-Dan'],
            ['receipt'=>'UK7CF11QTK','date'=>'2026-11-07','time'=>'17:51:45','paid_in'=>3700.00,'withdrawn'=>0.00,'balance'=>38756.00,'party'=>'akoko **** Alex','account'=>'MARA-KQ8JSCB'],
            ['receipt'=>'UK7CF11OPT','date'=>'2026-11-07','time'=>'17:22:06','paid_in'=>3720.00,'withdrawn'=>0.00,'balance'=>35056.00,'party'=>'akoko **** Alex','account'=>'MARA-S8T3LK0'],
            ['receipt'=>'UK7CF11GKH','date'=>'2026-11-07','time'=>'17:17:44','paid_in'=>1180.00,'withdrawn'=>0.00,'balance'=>31336.00,'party'=>'akoko **** Alex','account'=>'MARA-EEVATWR'],
            ['receipt'=>'UK6BN1K8AU','date'=>'2026-11-06','time'=>'16:20:18','paid_in'=>380.00,'withdrawn'=>0.00,'balance'=>30156.00,'party'=>'Abonyo **** Wenwa','account'=>'MARA-KUASNRU'],
            ['receipt'=>'UK61A0QXSF','date'=>'2026-11-06','time'=>'15:40:39','paid_in'=>15600.00,'withdrawn'=>0.00,'balance'=>29776.00,'party'=>'DANCUN OJODEH','account'=>'MARA-RKIK7QC'],
            ['receipt'=>'UK6BI11QPS','date'=>'2026-11-06','time'=>'11:20:26','paid_in'=>1560.00,'withdrawn'=>0.00,'balance'=>14176.00,'party'=>'DANISH ORIRI','account'=>'Mara Danish'],
            ['receipt'=>'UK55H12J7C','date'=>'2026-11-05','time'=>'14:46:37','paid_in'=>380.00,'withdrawn'=>0.00,'balance'=>12616.00,'party'=>'FAITH **** OYUGI','account'=>'MARA-KGFUQBU'],
            ['receipt'=>'UK5CF0RCSZ','date'=>'2026-11-05','time'=>'14:04:36','paid_in'=>1140.00,'withdrawn'=>0.00,'balance'=>12236.00,'party'=>'akoko **** Alex','account'=>'MARA-SDNXVJG'],
            ['receipt'=>'UK5CF0R4TA','date'=>'2026-11-05','time'=>'14:02:19','paid_in'=>380.00,'withdrawn'=>0.00,'balance'=>11096.00,'party'=>'akoko **** Alex','account'=>'MARA-AQYSVJB'],
            ['receipt'=>'UK5QZ0QCBB','date'=>'2026-11-05','time'=>'13:13:39','paid_in'=>380.00,'withdrawn'=>0.00,'balance'=>10716.00,'party'=>'ANGELA ODERO','account'=>'MARA-AM9LRP0'],
            ['receipt'=>'UK5BN1EH5A','date'=>'2026-11-05','time'=>'12:09:50','paid_in'=>380.00,'withdrawn'=>0.00,'balance'=>10336.00,'party'=>'Abonyo **** Wenwa','account'=>'MARA-4PGGWMH'],
            ['receipt'=>'UK58C10XBZ','date'=>'2026-11-05','time'=>'11:45:57','paid_in'=>780.00,'withdrawn'=>0.00,'balance'=>9956.00,'party'=>'VERONICA ATIENO','account'=>'MARA-QOWS0QX'],
            ['receipt'=>'UK43S1A3BQ','date'=>'2026-11-04','time'=>'18:11:48','paid_in'=>200.00,'withdrawn'=>0.00,'balance'=>9176.00,'party'=>'Atieno **** Otieno','account'=>'MARA-AO-01A1'],
            ['receipt'=>'UK49510EHG','date'=>'2026-11-04','time'=>'17:03:00','paid_in'=>200.00,'withdrawn'=>0.00,'balance'=>8976.00,'party'=>'David **** Odero','account'=>'MARA-D-01A1'],
            ['receipt'=>'UK4BI0T199','date'=>'2026-11-04','time'=>'14:22:35','paid_in'=>400.00,'withdrawn'=>0.00,'balance'=>8776.00,'party'=>'DANISH ORIRI','account'=>'MARA-DRMPWR9'],
            ['receipt'=>'UK4QW15G9N','date'=>'2026-11-04','time'=>'10:48:05','paid_in'=>780.00,'withdrawn'=>0.00,'balance'=>8376.00,'party'=>'JOSEPH **** MUGA','account'=>'MARA-JZQI6EF'],
            ['receipt'=>'UK4QQ0KRMU','date'=>'2026-11-04','time'=>'10:19:56','paid_in'=>500.00,'withdrawn'=>0.00,'balance'=>7596.00,'party'=>'DEDAN **** ADAM','account'=>'MARA(DEDAN ADAM\'S)'],
            ['receipt'=>'UK4BN18X6Q','date'=>'2026-11-04','time'=>'09:34:50','paid_in'=>5.00,'withdrawn'=>0.00,'balance'=>7096.00,'party'=>'Abonyo **** Wenwa','account'=>'MARA-O9AFAPR'],
            ['receipt'=>'UK3BI0P1E4','date'=>'2026-11-03','time'=>'16:28:41','paid_in'=>7086.00,'withdrawn'=>0.00,'balance'=>7091.00,'party'=>'DANISH ORIRI','account'=>'Mara Danish'],
            ['receipt'=>'UK3BN15SR2','date'=>'2026-11-03','time'=>'15:07:53','paid_in'=>5.00,'withdrawn'=>0.00,'balance'=>5.00,'party'=>'Abonyo **** Wenwa','account'=>'Jay'],
            ['receipt'=>'UK3TE5OEY8','date'=>'2026-11-03','time'=>'14:17:42','paid_in'=>0.00,'withdrawn'=>15120.00,'balance'=>0.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UK3HR0PV97','date'=>'2026-11-03','time'=>'09:21:55','paid_in'=>200.00,'withdrawn'=>0.00,'balance'=>15120.00,'party'=>'Steven **** Odhiambo','account'=>'MARA'],
            ['receipt'=>'UK3BN13MQU','date'=>'2026-11-03','time'=>'01:34:56','paid_in'=>5.00,'withdrawn'=>0.00,'balance'=>14920.00,'party'=>'Abonyo **** Wenwa','account'=>'MARA'],
            ['receipt'=>'UK3BN13P1D','date'=>'2026-11-03','time'=>'01:33:14','paid_in'=>5.00,'withdrawn'=>0.00,'balance'=>14915.00,'party'=>'Abonyo **** Wenwa','account'=>'Mara'],
            ['receipt'=>'UK2TE5N65W','date'=>'2026-11-02','time'=>'19:04:01','paid_in'=>0.00,'withdrawn'=>13500.00,'balance'=>14910.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UK2TE5ME66','date'=>'2026-11-02','time'=>'09:41:29','paid_in'=>0.00,'withdrawn'=>5000.00,'balance'=>28410.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UK1N00A5HY','date'=>'2026-11-01','time'=>'17:28:32','paid_in'=>8000.00,'withdrawn'=>0.00,'balance'=>33410.00,'party'=>'GODFRED BWANA','account'=>'Mara'],
            ['receipt'=>'UK1TE5KT6K','date'=>'2026-11-01','time'=>'10:04:50','paid_in'=>0.00,'withdrawn'=>5700.00,'balance'=>25410.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UJUIV0AHTK','date'=>'2026-10-30','time'=>'15:20:43','paid_in'=>18700.00,'withdrawn'=>0.00,'balance'=>31110.00,'party'=>'HARRIET OTIENO','account'=>'Mara H'],
            ['receipt'=>'UJULBBPX1F','date'=>'2026-10-30','time'=>'14:27:22','paid_in'=>200.00,'withdrawn'=>0.00,'balance'=>12410.00,'party'=>'Stephen Owiti','account'=>'MARA STEPHEN'],
            ['receipt'=>'UJU7Q0LNPG','date'=>'2026-10-30','time'=>'13:35:15','paid_in'=>8600.00,'withdrawn'=>0.00,'balance'=>12210.00,'party'=>'Santos **** omollo','account'=>'MARA Cathy Omolo'],
            ['receipt'=>'UJUJKBTAGD','date'=>'2026-10-30','time'=>'12:50:10','paid_in'=>500.00,'withdrawn'=>0.00,'balance'=>3610.00,'party'=>'Sharon Adhiambo','account'=>'MARA SHARON'],
            ['receipt'=>'UJTQQBRFLB','date'=>'2026-10-29','time'=>'15:12:17','paid_in'=>500.00,'withdrawn'=>0.00,'balance'=>3110.00,'party'=>'DEDAN **** ADAM','account'=>'MARA{DEDAN)'],
            ['receipt'=>'UJSQQBLSW2','date'=>'2026-10-28','time'=>'10:26:42','paid_in'=>500.00,'withdrawn'=>0.00,'balance'=>2610.00,'party'=>'DEDAN **** ADAM','account'=>'MARA(ADAM\'S)'],
            ['receipt'=>'UJS7Q0BSRF','date'=>'2026-10-28','time'=>'09:29:53','paid_in'=>390.00,'withdrawn'=>0.00,'balance'=>2110.00,'party'=>'Santos **** omollo','account'=>'MARA Cathy'],
            ['receipt'=>'UJR7Q070WG','date'=>'2026-10-27','time'=>'09:27:50','paid_in'=>1720.00,'withdrawn'=>0.00,'balance'=>1720.00,'party'=>'Santos **** omollo','account'=>'MARA Cathy'],
            ['receipt'=>'UJNTE56G7A','date'=>'2026-10-23','time'=>'16:40:30','paid_in'=>0.00,'withdrawn'=>10000.00,'balance'=>0.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UJNOBBD2MG','date'=>'2026-10-23','time'=>'16:01:27','paid_in'=>10000.00,'withdrawn'=>0.00,'balance'=>10000.00,'party'=>'ABONYO **** WENWA','account'=>'MARA- WATER'],
            ['receipt'=>'UJNTE56FRH','date'=>'2026-10-23','time'=>'15:39:06','paid_in'=>0.00,'withdrawn'=>40000.00,'balance'=>0.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UJKTE517CR','date'=>'2026-10-20','time'=>'12:30:16','paid_in'=>0.00,'withdrawn'=>40000.00,'balance'=>40000.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UJJKYB3N41','date'=>'2026-10-19','time'=>'20:15:39','paid_in'=>50000.00,'withdrawn'=>0.00,'balance'=>80000.00,'party'=>'Derrick **** Langat','account'=>'AFRIGIG'],
            ['receipt'=>'UJJOBATP0R','date'=>'2026-10-19','time'=>'09:39:26','paid_in'=>30000.00,'withdrawn'=>0.00,'balance'=>30000.00,'party'=>'ABONYO **** WENWA','account'=>'MARA- WATER'],
            ['receipt'=>'UJATE4KDZR','date'=>'2026-10-10','time'=>'11:01:56','paid_in'=>0.00,'withdrawn'=>315015.00,'balance'=>0.00,'party'=>'Settlement to the organization account','account'=>''],
            ['receipt'=>'UJAKY9XF9K','date'=>'2026-10-10','time'=>'10:55:22','paid_in'=>65000.00,'withdrawn'=>0.00,'balance'=>315015.00,'party'=>'Derrick **** Langat','account'=>'MARA SUPPLIES'],
            ['receipt'=>'UJAKY9XJPP','date'=>'2026-10-10','time'=>'10:54:14','paid_in'=>250000.00,'withdrawn'=>0.00,'balance'=>250015.00,'party'=>'Derrick **** Langat','account'=>'MARA SUPPLIES'],
            ['receipt'=>'UJ9BN9Y8ZF','date'=>'2026-10-09','time'=>'12:47:32','paid_in'=>5.00,'withdrawn'=>0.00,'balance'=>15.00,'party'=>'Abonyo **** Wenwa','account'=>'Jay'],
            ['receipt'=>'UJ9BN9WU0A','date'=>'2026-10-09','time'=>'00:47:14','paid_in'=>10.00,'withdrawn'=>0.00,'balance'=>10.00,'party'=>'Abonyo **** Wenwa','account'=>'MARA-01A11D7'],
        ];
    }
}
