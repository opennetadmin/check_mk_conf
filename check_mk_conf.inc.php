<?php

// Lets do some initial install related stuff
if (file_exists(dirname(__FILE__)."/install.php")) {
    printmsg("DEBUG => Found install file for plugin.", 1);
    include(dirname(__FILE__)."/install.php");
}



// Make sure we have necessary functions & DB connectivity
require_once($conf['inc_functions_db']);



///////////////////////////////////////////////////////////////////////
//  Function: check_mk_conf (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function.  0 on success, non-zero on
//         error.  All errors messages are stored in $self['error'].
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = check_mk_conf('tag=test');
///////////////////////////////////////////////////////////////////////
function check_mk_conf($options="") {
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.00';
    // default search tag 
    $search_tag = 'monitor';
    $lifecycles = array();

    printmsg("DEBUG => check_mk_conf({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or !($options['wato_host_tags'] or $options['all_hosts'] or $options['groups']) ) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOM

check_mk_conf-v{$version}
Generate check_mk configuration of specified type

  Synopsis: check_mk_conf [KEY=VALUE] ...

  Required:
    wato_host_tags    Configs for multisite. Store in multisite.d
    all_hosts         Configs for check_mk. Store in conf.d
    groups            Configs for check_mk. Store in conf.d

  Optional:
    tag=<name>  Tag name to select which hosts to monitor. Default: {$search_tag}

Processes all Custom Attributes except those defined in the ignore list into a
check_mk tag named <ca_key>-<ca_value>.  Also all ONA tags are directly associated as well.

Host and service groups will live in their own file. Use the groups option to build it

Wato host tags are only comprised of custom attributes from ONA.

EOM
        ));
    }

  // Lets ignore some lame custom attribute types.
  //TODO: ignore specified list of custom attribute names
  // This could be passed in to the module and or read from a config file in the future.  for now it will be static.
  $ca_type_ignore="custom_attribute_types.name not in ('Express Service Code','Warranty / Service Expiration Date','Serial Number','Operating System','Service Tag')";

  // Set input value for our search tag
  if (isset($options['tag'])) { $search_tag = $options['tag']; }

  // Build configuration for check_mk all_hosts value
  if (isset($options['all_hosts']) or isset($options['groups'])) {
    // Query to gather all custom attributes for hosts with our monitor tag
    $q="
select h.id host_id,
       concat(dns.name,'.',b.name) fqdn,
       custom_attribute_types.name name,
       ca.value value
from dns,
     domains b,
     custom_attributes ca,
     custom_attribute_types,
     hosts h
where dns.domain_id = b.id
and   h.primary_dns_id = dns.id
and   h.id in ( select reference from tags where type = 'host' and name = '{$search_tag}' )
and h.id = ca.table_id_ref
and ca.table_name_ref = 'hosts'
AND ca.custom_attribute_type_id = custom_attribute_types.id
and {$ca_type_ignore}
order BY h.id";


    // exectue the query
    $rs = $onadb->Execute($q);
    if ($rs === false or (!$rs->RecordCount())) {
        $self['error'] = 'ERROR => check_mk_conf(): Custom Attribute SQL query failed: ' . $onadb->ErrorMsg();
        printmsg($self['error'], 0);
        $exit += 1;
    }

    // Loop through hosts with attributes
    while ($ca = $rs->FetchRow()) {
      // Capture the tags
      if ( $ca['name'] == 'lifecycle' ) { $lifecycles[] = $ca['value']; }
      // build check_mk tag list from custom attributes like "<cakey>-<cavalue>"
      $cmkcalist[$ca['fqdn']]['calist']="{$cmkcalist[$ca['fqdn']]['calist']}|{$ca['name']}-{$ca['value']}";

      // Query for tag selection
      $tag_query="
select GROUP_CONCAT(tags.name ORDER BY tags.name ASC SEPARATOR '|') tags
from hosts h
INNER JOIN tags ON (h.id = tags.reference)
where   h.id = {$ca['host_id']}
GROUP BY h.id";

      // execute tag query
      $tag_rs = $onadb->Execute($tag_query);
      $taglist = $tag_rs->FetchRow();
      // Store tags in our array
      $cmkcalist[$ca['fqdn']]['taglist'] = $taglist['tags'];

    }


    // close record sets
    $rs->Close();
#    $tag_rs->Close();
  }

  if (isset($options['all_hosts'])) {
    $text = "
# Lock wato from making changes
_lock = True

# Autogenerated by OpenNetAdmin check_mk_conf module. Do not edit here.
all_hosts += [
";

    // Print out our combined results for this host
    foreach ($cmkcalist as $cafqdn => $cahost) {
      $text .= "  \"{$cafqdn}{$cahost['calist']}|{$cahost['taglist']}|/\" + FOLDER_PATH + \"/\",\n";
    }
    $text .= "]\n\n";
  } // end if all_hosts

  if (isset($options['groups'])) {
    // This is a non generic use case.. everyone may not need this
    if (is_array($lifecycles)) {
      // Get a list of lifecycles and define them as host/service groups
      $text .= "# Autogenerated host/service groups. Do not edit here.\n";
      $text .= "define_hostgroups = True\n";
      $text .= "host_groups += [\n";
      $lifecycles=array_unique($lifecycles);
      foreach ($lifecycles as $lifecycle) {
        $text .= "  ('lifecycle-{$lifecycle}', ['lifecycle-{$lifecycle}'], ALL_HOSTS),\n";
      }
      $text .= "]\n\n";
      $text .= "define_servicegroups = True\n";
      $text .= "service_groups += [\n";
      $lifecycles=array_unique($lifecycles);
      foreach ($lifecycles as $lifecycle) {
        $text .= "  ('lifecycle-{$lifecycle}', ['lifecycle-{$lifecycle}'], ALL_HOSTS, ALL_SERVICES),\n";
      }
      $text .= "]\n\n";
    }
  } // end if host_groups





  // Build wato host tags, this provides available tags to the gui
  if (isset($options['wato_host_tags'])) {

    // Get a list of all of our CA types minus the ignore list
    list($status, $rows, $ca_types) = db_get_records($onadb, 'custom_attribute_types', $ca_type_ignore, 'name ASC');
    foreach ($ca_types as $ca_type) {
      $ca_query="select distinct value from custom_attributes where custom_attribute_type_id = {$ca_type['id']} order by value ASC";
      $rs = $onadb->Execute($ca_query);
      $rows = $rs->RecordCount();
      if ($rows) {
        // Loop through record set
        while ($ca = $rs->FetchRow()) {
          // Gather Lifecycle for later
          if ( $ca_type['name'] == 'lifecycle' ) { $lifecycles[] = $ca['value']; }
          // For boolean tags, show both true and false states
          if ( stristr($ca['value'],'Y') || stristr($ca['value'],'N') || stristr($ca['value'],'true') || stristr($ca['value'],'false') ) {
            $calist[$ca_type['name']][] = 'true';
            $calist[$ca_type['name']][] = 'false';
          } else {
            $calist[$ca_type['name']][] = $ca['value'];
          }
        }
      }
    }
    // Print out our custom attributes
    // TODO: could print a lifecycle-role option for each as well??
    $text = "
# Autogenerated by OpenNetAdmin check_mk_conf module. Do not edit here.
wato_host_tags += [
";
    foreach ($calist as $catype => $ca) {
      $text .= "  ('ona-{$catype}', u'ONA {$catype}', [\n";
      $ca=array_unique($ca);
      foreach ($ca as $cavalue) {
        $text .= "  ('{$catype}-".strtolower($cavalue)."', u'".strtolower($cavalue)."', []),\n";
      }
      $text .= "  ]),\n";
    }
    $text .= "]\n";

    // Close record set
    $rs->Close();
  }
/*



TODO: maybe generate host aliases???
# Autogenerated EC2 host aliases. Do not edit here.
extra_host_conf.setdefault('alias', []).extend([
  (u'prod01-api01', ['prod01-api01.us-west-2.kountaccess.com']),

*/

    // Return the success notice
    return(array(0, $text));
}


?>
