<?php
/**
 * pnotes_full_add.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018-2020 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once("../../globals.php");
require_once("$srcdir/pnotes.inc");
require_once("$srcdir/patient.inc");
require_once("$srcdir/acl.inc");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/gprelations.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Core\Header;

if ($_GET['set_pid']) {
    require_once("$srcdir/pid.inc");
    setpid($_GET['set_pid']);
}

// form parameter docid can be passed to restrict the display to a document.
$docid = empty($_REQUEST['docid']) ? 0 : intval($_REQUEST['docid']);

// form parameter orderid can be passed to restrict the display to a procedure order.
$orderid = empty($_REQUEST['orderid']) ? 0 : intval($_REQUEST['orderid']);

$patient_id = $pid;
if ($docid) {
    $row = sqlQuery("SELECT foreign_id FROM documents WHERE id = ?", array($docid));
    $patient_id = intval($row['foreign_id']);
} else if ($orderid) {
    $row = sqlQuery("SELECT patient_id FROM procedure_order WHERE procedure_order_id = ?", array($orderid));
    $patient_id = intval($row['patient_id']);
}

// Check authorization.
if (!acl_check('patients', 'notes', '', array('write','addonly'))) {
    die(xlt('Not authorized'));
}

$tmp = getPatientData($patient_id, "squad");
if ($tmp['squad'] && ! acl_check('squads', $tmp['squad'])) {
    die(xlt('Not authorized for this squad.'));
}

//the number of records to display per screen
$N = 25;

$mode   = $_REQUEST['mode'];
$offset = $_REQUEST['offset'];
$form_active = $_REQUEST['form_active'];
$form_inactive = $_REQUEST['form_inactive'];
$noteid = $_REQUEST['noteid'];
$form_doc_only = isset($_POST['mode']) ? (empty($_POST['form_doc_only']) ? 0 : 1) : 1;

if (!isset($offset)) {
    $offset = 0;
}

// if (!isset($active)) $active = "all";

$active = 'all';
if ($form_active) {
    if (!$form_inactive) {
        $active = '1';
    }
} else {
    if ($form_inactive) {
        $active = '0';
    } else {
        $form_active = $form_inactive = '1';
    }
}

// this code handles changing the state of activity tags when the user updates
// them through the interface
if (isset($mode)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    if ($mode == "update") {
        foreach ($_POST as $var => $val) {
            if (strncmp($var, 'act', 3) == 0) {
                $id = str_replace("act", "", $var);
                if ($_POST["chk$id"]) {
                    reappearPnote($id);
                } else {
                    disappearPnote($id);
                }

                if ($docid) {
                    setGpRelation(1, $docid, 6, $id, !empty($_POST["lnk$id"]));
                }

                if ($orderid) {
                    setGpRelation(2, $orderid, 6, $id, !empty($_POST["lnk$id"]));
                }
            }
        }
    } elseif ($mode == "new") {
        $note = $_POST['note'];
        if ($noteid) {
            updatePnote($noteid, $note, $_POST['form_note_type'], $_POST['assigned_to']);
            $noteid = '';
        } else {
            $noteid = addPnote(
                $patient_id,
                $note,
                $userauthorized,
                '1',
                $_POST['form_note_type'],
                $_POST['assigned_to']
            );
        }

        if ($docid) {
            setGpRelation(1, $docid, 6, $noteid);
        }

        if ($orderid) {
            setGpRelation(2, $orderid, 6, $noteid);
        }

        $noteid = '';
    } elseif ($mode == "delete") {
        if ($noteid) {
            deletePnote($noteid);
            EventAuditLogger::instance()->newEvent("delete", $_SESSION['authUser'], $_SESSION['authProvider'], "pnotes: id ".$noteid);
        }

        $noteid = '';
    }
}

$title = '';
$assigned_to = $_SESSION['authUser'];
if ($noteid) {
    $prow = getPnoteById($noteid, 'title,assigned_to,body,date');
    $title = $prow['title'];
    $assigned_to = $prow['assigned_to'];
    $datetime = $prow['date'];
}

// Get the users list.  The "Inactive" test is a kludge, we should create
// a separate column for this.
$ures = sqlStatement("SELECT username, fname, lname FROM users " .
 "WHERE username != '' AND active = 1 AND " .
 "( info IS NULL OR info NOT LIKE '%Inactive%' ) " .
 "ORDER BY lname, fname");

$pres = getPatientData($patient_id, "lname, fname");
$patientname = $pres['lname'] . ", " . $pres['fname'];

//retrieve all notes
$result = getPnotesByDate(
    "",
    $active,
    'id,date,body,user,activity,title,assigned_to',
    $patient_id,
    $N,
    $offset
);
?>

<html>
<head>

<?php Header::setupHeader(['common', 'datetime-picker', 'opener']); ?>

<script>
function submitform(attr) {
    if (attr == "newnote") {
        document.forms[0].submit();
    }
}
</script>
</head>
<body class="body_top">
<div id="pnotes"> <!-- large outer DIV -->
<?php
$title_docname = "";
if ($docid) {
    $title_docname .= " " . xl("linked to document") . " ";
    $d = new Document($docid);
    $title_docname .= $d->get_url_file();
}

if ($orderid) {
    $title_docname .= " " . xl("linked to procedure order") . " $orderid";
}

$urlparms = "docid=" . attr_url($docid) . "&orderid= " . attr_url($orderid);
?>

<form border='0' method='post' name='new_note' id="new_note" action='pnotes_full.php?<?php echo $urlparms; ?>'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<div>
    <div id="pnotes_title">
        <span class="title"><?php echo xlt('Patient Message') . text($title_docname); ?></span>
    </div>
    <div>
        <?php if ($noteid) { ?>
            <!-- existing note -->
            <a href="#" class="css_button" id="printnote"><span><?php echo xlt('View Printable Version'); ?></span></a>
        <?php } ?>
        <a class="css_button large_button" id='cancel' href='javascript:;'><span class='css_button_span large_button_span'><?php echo xlt('Cancel'); ?></span></a>
    </div>
</div>
<br/>

<input type='hidden' name='mode' id="mode" value="new">
<input type='hidden' name='trigger' id="trigger" value="add">
<input type='hidden' name='offset' id="offset" value="<?php echo attr($offset); ?>">
<input type='hidden' name='form_active' id="form_active" value="<?php echo attr($form_active); ?>">
<input type='hidden' name='form_inactive' id="form_inactive" value="<?php echo attr($form_inactive); ?>">
<input type='hidden' name='noteid' id="noteid" value="<?php echo attr($noteid); ?>">
<input type='hidden' name='form_doc_only' id="form_doc_only" value="<?php echo attr($form_doc_only); ?>">
<table border='0' cellspacing='8'>
 <tr>
  <td class='text'>
    <?php
    if ($noteid) {
       // Modified 6/2009 by BM to incorporate the patient notes into the list_options listings
        echo xlt('Amend Existing Message') .
        "<b> &quot;" . generate_display_field(array('data_type'=>'1','list_id'=>'note_type'), $title) . "&quot;</b>\n";
    } else {
        echo xlt('Add New Message') . "\n";
    }
    ?>
  </td>
 </tr>
 <tr>
  <td class='text'>
    <br/>
   <b><?php echo xlt('Type'); ?>:</b>
    <?php
   // Added 6/2009 by BM to incorporate the patient notes into the list_options listings
    generate_form_field(array('data_type'=>1,'field_id'=>'note_type','list_id'=>'note_type','empty_title'=>'SKIP'), $title);
    ?>
   &nbsp; &nbsp;
   <b><?php echo xlt('To{{Destination}}'); ?>:</b>
   <select name='assigned_to'>
<?php
while ($urow = sqlFetchArray($ures)) {
    echo "    <option value='" . attr($urow['username']) . "'";
    if ($urow['username'] == $assigned_to) {
        echo " selected";
    }

    echo ">" . text($urow['lname']);
    if ($urow['fname']) {
        echo text(", ".$urow['fname']);
    }

    echo "</option>\n";
}
?>
   <option value=''><?php echo xlt('Mark Message as Completed'); ?></option>
   </select>
  </td>
 </tr>
<?php if ($GLOBALS['messages_due_date']) { ?>
 <tr>
     <td>
         <b><?php echo xlt('Due date'); ?>:</b>
        <?php
        generate_form_field(array('data_type' => 4, 'field_id' => 'datetime', 'edit_options' => 'F'), empty($datetime) ? date('Y-m-d H:i') : $datetime);
        ?>
     </td>
 </tr>
    <?php
}
?>
<tr>
    <td>
<?php
if ($noteid) {
    $body = $prow['body'];
    $body = preg_replace(array('/(\sto\s)-patient-(\))/', '/(:\d{2}\s\()' . $patient_id . '(\sto\s)/'), '${1}' . $patientname . '${2}', $body);
    $body = nl2br(text(oeFormatPatientNote($body)));
    echo "<div class='text'>".$body."</div>";
}
?>
    </td>
</tr>
<tr>
    <td>
        <textarea name='note' id='note' rows='4' cols='58'></textarea>
    </td>
</tr>
<tr>
    <td>
        <?php if ($noteid) { ?>
            <!-- existing note -->
            <a href="#" class="css_button" id="newnote" title="<?php echo xla('Add as a new message'); ?>" ><span><?php echo xlt('Save as new message'); ?></span></a>
            <a href="#" class="css_button" id="appendnote" title="<?php echo xla('Append to the existing message'); ?>"><span><?php echo xlt('Append this message'); ?></span></a>
        <?php } else { ?>
            <a href="#" class="css_button" id="newnote" title="<?php echo xla('Add as a new message'); ?>" ><span><?php echo xlt('Save as new message'); ?></span></a>
        <?php } ?>
    </td>
</tr>

</table>
<br>
<br>
</form>
<form border='0' method='post' name='update_activity' id='update_activity'
 action="pnotes_full.php?<?php echo $urlparms; ?>">
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

<!-- start of previous notes DIV -->
<div class=pat_notes>


<input type='hidden' name='mode' value="update">
<input type='hidden' name='offset' id='noteid' value="<?php echo attr($offset); ?>">
<input type='hidden' name='noteid' id='noteid' value="0">
</form>

<table width='400' border='0' cellpadding='0' cellspacing='0'>
 <tr>
  <td>
<?php
if ($offset > ($N-1)) {
    echo "   <a class='link' href='pnotes_full.php" .
    "?$urlparms" .
    "&form_active=" . attr_url($form_active) .
    "&form_inactive=" . attr_url($form_inactive) .
    "&form_doc_only=" . attr_url($form_doc_only) .
    "&offset=" . attr_url($offset-$N) . "' onclick='top.restoreSession()'>[" .
    xlt('Previous') . "]</a>\n";
}
?>
  </td>
  <td align='right'>
<?php
if ($result_count == $N) {
    echo "   <a class='link' href='pnotes_full.php" .
    "?$urlparms" .
    "&form_active=" . attr_url($form_active) .
    "&form_inactive=" . attr_url($form_inactive) .
    "&form_doc_only=" . attr_url($form_doc_only) .
    "&offset=" . attr_url($offset+$N) . "' onclick='top.restoreSession()'>[" .
    xlt('Next') . "]</a>\n";
}
?>
  </td>
 </tr>
</table>

</div> <!-- close the previous-notes DIV -->

<script language='JavaScript'>

<?php
if ($_GET['set_pid']) {
    $ndata = getPatientData($patient_id, "fname, lname, pubpid");
    ?>
 parent.left_nav.setPatient(<?php echo js_escape($ndata['fname']." ".$ndata['lname']) . "," . js_escape($patient_id) . "," . js_escape($ndata['pubpid']) . ",window.name"; ?>);
    <?php
}

// If this note references a new patient document, pop up a display
// of that document.
//
if ($noteid /* && $title == 'New Document' */) {
    $prow = getPnoteById($noteid, 'body');
    if (preg_match('/New scanned document (\d+): [^\n]+\/([^\n]+)/', $prow['body'], $matches)) {
        $docid = $matches[1];
        $docname = $matches[2];
        ?>
     window.open('../../../controller.php?document&retrieve&patient_id=<?php echo attr_url($patient_id); ?>&document_id=<?php echo attr_url($docid); ?>&<?php echo attr_url($docname)?>&as_file=true',
  '_blank', 'resizable=1,scrollbars=1,width=600,height=500');
        <?php
    }
}
?>

</script>

</div> <!-- end outer 'pnotes' -->

</body>

<script language="javascript">

// jQuery stuff to make the page a little easier to use

$(function(){
    $("#appendnote").click(function() { AppendNote(); });
    $("#newnote").click(function() { NewNote(); });
    $("#printnote").click(function() { PrintNote(); });

    $(".change_activity").click(function() { top.restoreSession(); $("#update_activity").submit(); });

    $(".deletenote").click(function() { DeleteNote(this); });

    $(".noterow").mouseover(function() { $(this).toggleClass("highlight"); });
    $(".noterow").mouseout(function() { $(this).toggleClass("highlight"); });
    $(".notecell").click(function() { EditNote(this); });

    $("#note").focus();

    var EditNote = function(note) {
        top.restoreSession();
        $("#noteid").val(note.id);
        $("#mode").val("");
        $("#new_note").submit();
    }

    var NewNote = function () {
        top.restoreSession();
        $("#noteid").val('');
        $("#new_note").submit();
    }

    var AppendNote = function () {
        top.restoreSession();
        $("#new_note").submit();
    }

    var PrintNote = function () {
        top.restoreSession();
        window.open('pnotes_print.php?noteid=<?php echo attr_url($noteid); ?>', '_blank', 'resizable=1,scrollbars=1,width=600,height=500');
    }

    var DeleteNote = function(note) {
        if (confirm(<?php echo xlj('Are you sure you want to delete this message?'); ?> + '\n ' + <?php echo xlj('This action CANNOT be undone.'); ?>)) {
            top.restoreSession();
            // strip the 'del' part of the object's ID
            $("#noteid").val(note.id.replace(/del/, ""));
            $("#mode").val("delete");
            $("#new_note").submit();
        }
    }

});
$(function(){
    $("#cancel").click(function() {
          dlgclose();
     });

    $("#new_note").submit(function (event) {
        event.preventDefault();
        var post_url = $(this).attr("action");
        var request_method = $(this).attr("method");
        var form_data = $(this).serialize();

        $.ajax({
            url: post_url,
            type: request_method,
            data: form_data
        }).done(function (r) { //
            dlgclose('refreshme', false);
        });
    });

    $('.datetimepicker').datetimepicker({
        <?php $datetimepicker_timepicker = true; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
        ,minDate : 0 //only future
    })

});
</script>

</html>
