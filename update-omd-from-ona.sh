#!/bin/bash
#
# This script is intended to be run as the OMD master site user
# It will run the dcm.pl check_mk_conf module to populate hosts and tags into
# the check_mk configuration, run an inventory and then restart check_mk.
#
# You should place this script somewhere on your OMD server and run it via cron.
# It requires dcm.pl to be installed on the OMD server as well.

# Set Sitename
SITENAME=$1
if [[ "$SITENAME" == "" ]]
then
   SITENAME=$OMD_SITE
   echo "*** Using ENV SITE $OMD_SITE"
fi

# Set some variables
PID=$$
WHOAMI=$(/usr/bin/whoami)
SCRIPT=$(basename $0)
BASEDIR=$(dirname $0)
TMPDIR=/tmp
OMDROOT=/omd/sites/$SITENAME
CONFDIR=$OMDROOT/etc/check_mk/conf.d/wato/ona
MSITEDIR=$OMDROOT/etc/check_mk/multisite.d
DCMCMD=/opt/ona/bin/dcm.pl

# check if confdir exists, if not create it
[ ! -d $CONFDIR ] && echo "*** $CONFDIR does not exist, creating it.."
[ ! -d $CONFDIR ] && mkdir $CONFDIR


# Verify running as user passed on cli
if [[ "$WHOAMI" != "$SITENAME" ]] ; then
   echo "*** Must run as site user $SITENAME, exiting"
   exit 1
fi

echo "#####################################################"
echo "*** $SCRIPT started at $(date)"
#uncomment for debug
#set -x

# Source sites's profile in case we're running from cron
. $OMDROOT/.profile

# Extract the ONA hosts to monitor
echo "*** Running check_mk_conf all_hosts option to build hosts.mk"
$DCMCMD -r check_mk_conf all_hosts > $CONFDIR/hosts.mk

# Extract the ONA groups to monitor
echo "*** Running check_mk_conf groups option to build groups.mk"
$DCMCMD -r check_mk_conf groups > $CONFDIR/groups.mk

# Extract the ONA groups for WATO
echo "*** Running check_mk_conf groups option to build groups.mk"
$DCMCMD -r check_mk_conf wato_groups > $CONFDIR/../groups.mk

# Extract ONA Custom Attribute lists
echo "*** Running check_mk_conf wato_host_tags option to build onatags.mk"
$DCMCMD -r check_mk_conf wato_host_tags > $MSITEDIR/onatags.mk

# Restart the local cmk instance
if [[ $? -eq 0 ]] ; then
   echo "*** Reloading config with cmk -O"
   $OMDROOT/bin/cmk -O
else
   "*** Error returned from cmk -O, exiting"
   exit 1
fi

# TODO: make the sync operation a flag to pass in. not everyone will have multisite.

# For multisite, issue a configuration sync between all of our sites
# http://mathias-kettner.de/checkmk_multisite_automation.html
# https://mathias-kettner.de/checkmk_multisite_login_dialog.html
# requires multisite cookie and a user with an automation secret
SECRET=$(cat $OMDROOT/var/check_mk/web/wato/automation.secret)
echo "*** Syncronizing config between all sites..."
SYNCOUTPUT=$(/usr/bin/curl -s "http://localhost/$SITENAME/check_mk/webapi.py?action=activate_changes&_username=wato&_secret=$SECRET&mode=all")
if [[ $? -ne 0 ]] ; then
  echo "*** ERROR Curl was unable to process"
  exit 1
fi
SYNCOUTPUTCODE=$(echo "$SYNCOUTPUT"|sed -e "s/.*'result_code': //g" -e "s/}//")
if [[ "$SYNCOUTPUTCODE" != "0" ]] ; then
  echo "*** ERROR Sync error msg: $SYNCOUTPUT"
  exit 1
fi

#### This script really should not do inventory.  CMK should on its own.
## Disable for now.. inventory should happen on sites themselves, not master
# Perform an inventory, then reload config
#echo "*** Running inventory with cmk -I"
#$OMDROOT/bin/cmk -I

# All done
echo "*** $SCRIPT finished at $(date)"
echo "#####################################################"
exit 0
